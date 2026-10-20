<?php

namespace app\common\openapi;

use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use WebmanTech\Swagger\DTO\ConfigOpenapiDocDTO;
use WebmanTech\Swagger\Overwrite\Generator as SwaggerGenerator;

/** 通过插件生成器离线生成契约；不启动 Webman 或访问业务服务。 */
final class Generator
{
  private const METHODS = [
    'get',
    'post',
    'put',
    'patch',
    'delete',
    'head',
    'options',
    'trace',
  ];

  public function __construct(private array $config) {}

  /** @return array{public: string, internal: string} */
  public function generate(array $routes): array
  {
    return $this->build($routes)[0];
  }

  /** 完整文档和来源索引在同一次插件分析中产生，不保留跨调用状态。 */
  private function build(array $routes): array
  {
    $options = new ConfigOpenapiDocDTO(
      scan_path: $this->config['scan_path'],
      openapi_validate: true,
      enable_dto_generator: false,
      expand_eloquent_model_enable: false,
      auto_load_schema_classes: [],
      clean_unused_components_enable: false,
      openapi_version: '3.0.3',
    );
    $generator = (new SwaggerGenerator($options))->init();
    // operationId 必须由维护者显式声明，禁止自动补全掩盖遗漏。
    $generator->withProcessorPipeline(
      fn($pipeline) => $pipeline->remove(
        \OpenApi\Processors\OperationId::class,
      ),
    );
    $logger = new class extends AbstractLogger {
      public function log(
        $level,
        string|\Stringable $message,
        array $context = [],
      ): void {
        if (
          in_array(
            $level,
            ['emergency', 'alert', 'critical', 'error', 'warning'],
            true,
          )
        ) {
          throw new RuntimeException('OpenAPI: ' . $message);
        }
      }
    };
    $analysis = new \WebmanTech\Swagger\Overwrite\Analysis(
      [],
      new \OpenApi\Context(['logger' => $logger, 'version' => '3.0.3']),
    );
    $document = $generator->generate(
      $options->getScanSources(),
      $analysis,
      validate: false,
    );
    if ($document === null) {
      throw new RuntimeException('OpenAPI 文档生成失败');
    }
    $data = json_decode($document->toJson(), false, 512, JSON_THROW_ON_ERROR);
    $operations = [];
    $ids = [];
    foreach ($data->paths ?? [] as $path => $item) {
      foreach (self::METHODS as $method) {
        if (!isset($item->$method)) {
          continue;
        }
        $operation = $item->$method;
        $key = strtoupper($method) . ' ' . $path;
        $audience = $operation->{'x-audience'} ?? null;
        if (!in_array($audience, ['public', 'internal'], true)) {
          throw new RuntimeException($key . ' 缺少或未知 x-audience');
        }
        $id = $operation->operationId ?? '';
        if (!is_string($id) || $id === '' || isset($ids[$id])) {
          throw new RuntimeException($key . ' 缺少或重复 operationId: ' . $id);
        }
        $ids[$id] = true;
        $operations[$key] = $audience;
      }
    }
    $actual = [];
    foreach ($routes as $route) {
      foreach ($route->getMethods() as $method) {
        // CORS 通配预检不属于业务接口；fallback 不在 getRoutes() 中。
        if (
          $method === 'OPTIONS' &&
          $route->getPath() === '[{path:.+}]' &&
          $route->getCallback() instanceof \Closure
        ) {
          continue;
        }
        $key = strtoupper($method) . ' ' . $route->getPath();
        if (isset($actual[$key])) {
          throw new RuntimeException('重复注册路由: ' . $key);
        }
        $actual[$key] = true;
      }
    }
    $missing = array_diff_key($actual, $operations);
    $extra = array_diff_key($operations, $actual);
    if ($missing) {
      throw new RuntimeException(
        'OpenAPI 缺少路由: ' . implode(', ', array_keys($missing)),
      );
    }
    if ($extra) {
      throw new RuntimeException(
        'OpenAPI 多余路由: ' . implode(', ', array_keys($extra)),
      );
    }
    if (isset($this->config['public_operations'])) {
      $public = array_keys(
        array_filter($operations, fn($audience) => $audience === 'public'),
      );
      $expected = $this->config['public_operations'];
      sort($public);
      sort($expected);
      if ($public !== $expected) {
        throw new RuntimeException('public 接口归属与批准清单不一致');
      }
    }
    // 校验完整文档后再拆分，未使用组件中的错误也不得悄悄消失。
    if (!$document->validate()) {
      throw new RuntimeException('OpenAPI 规范校验失败');
    }
    $this->references($data, $data);
    $result = [];
    foreach (['public', 'internal'] as $audience) {
      $filtered = unserialize(serialize($data));
      foreach ($filtered->paths as $path => $item) {
        $retained = false;
        foreach (self::METHODS as $method) {
          if (isset($item->$method)) {
            if ($item->$method->{'x-audience'} !== $audience) {
              unset($item->$method);
            } else {
              $retained = true;
            }
          }
        }
        if (!$retained) {
          unset($filtered->paths->$path);
        }
      }
      $this->prune($filtered);
      $result[$audience] = Yaml::dump(
        $this->sort($filtered),
        30,
        2,
        Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
      );
    }
    $pathModules = [];
    foreach ($document->paths as $item) {
      foreach (self::METHODS as $method) {
        $operation = $item->$method;
        if ($operation instanceof \OpenApi\Annotations\Operation) {
          $module = ArtifactLayout::sourceModule(
            $operation->_context->filename,
          );
          $audience = $operation->x['audience'];
          if (
            isset($pathModules[$audience][$item->path]) &&
            $pathModules[$audience][$item->path] !== $module
          ) {
            throw new RuntimeException(
              '同一路径的 Controller 来源模块不一致: ' . $item->path,
            );
          }
          $pathModules[$audience][$item->path] = $module;
        }
      }
    }
    $schemaModules = [];
    foreach ($document->components->schemas ?? [] as $schema) {
      $schemaModules[$schema->schema] = ArtifactLayout::sourceModule(
        $schema->_context->filename,
        true,
      );
    }
    return [$result, $pathModules, $schemaModules];
  }

  /** lint 与 check 仅读取；generate 在所有校验通过后才替换产物。 */
  public function execute(string $mode, array $routes, string $output): void
  {
    if (!in_array($mode, ['generate', 'lint', 'check'], true)) {
      throw new RuntimeException('命令必须为 generate、lint 或 check');
    }
    [$documents, $pathModules, $schemaModules] = $this->build($routes);
    $files = (new ArtifactLayout())->files(
      $documents,
      $pathModules,
      $schemaModules,
    );
    if ($mode === 'lint') {
      return;
    }
    (new ArtifactPublisher($output))->execute($mode, $files);
  }

  /** 查找并验证本地引用，返回可达组件；安全要求虽无 $ref 也参与遍历。 */
  private function references(mixed $node, stdClass $document): array
  {
    $references = [];
    if (!is_array($node) && !($node instanceof stdClass)) {
      return [];
    }
    foreach ($node as $key => $value) {
      if ($key === '$ref') {
        if (!is_string($value) || !str_starts_with($value, '#/')) {
          throw new RuntimeException(
            '不支持外部或非法 ref: ' . (string) $value,
          );
        }
        $parts = array_map(
          fn($part) => str_replace(['~1', '~0'], ['/', '~'], $part),
          explode('/', substr($value, 2)),
        );
        $target = $document;
        foreach ($parts as $part) {
          if (
            (!($target instanceof stdClass) && !is_array($target)) ||
            !array_key_exists($part, (array) $target)
          ) {
            throw new RuntimeException('悬空 ref: ' . $value);
          }
          $target = is_array($target) ? $target[$part] : $target->$part;
        }
        if (($parts[0] ?? '') === 'components' && count($parts) >= 3) {
          $references[$parts[1] . '/' . $parts[2]] = [$parts[1], $parts[2]];
        }
      }
      if ($key === 'security' && is_array($value)) {
        foreach ($value as $requirement) {
          foreach ($requirement as $name => $_) {
            if (!isset($document->components->securitySchemes->$name)) {
              throw new RuntimeException('悬空 security ref: ' . $name);
            }
            $references['securitySchemes/' . $name] = [
              'securitySchemes',
              $name,
            ];
          }
        }
      }
      if (
        $key === 'discriminator' &&
        $value instanceof stdClass &&
        isset($value->mapping)
      ) {
        foreach ($value->mapping as $reference) {
          if (!is_string($reference)) {
            throw new RuntimeException('非法 discriminator ref');
          }
          // 映射既允许本地引用，也允许已定义 schema 的名称。
          if (isset($document->components->schemas->$reference)) {
            $reference =
              '#/components/schemas/' .
              str_replace(['~', '/'], ['~0', '~1'], $reference);
          }
          $references += $this->references(
            (object) ['$ref' => $reference],
            $document,
          );
        }
      }
      $references += $this->references($value, $document);
    }
    return $references;
  }

  private function prune(stdClass $document): void
  {
    $root = clone $document;
    unset($root->components);
    $pending = $this->references($root, $document);
    $retained = [];
    while ($pending) {
      $key = array_key_first($pending);
      [$section, $name] = $pending[$key];
      unset($pending[$key]);
      if (isset($retained[$key])) {
        continue;
      }
      $retained[$key] = true;
      $pending += array_diff_key(
        $this->references($document->components->$section->$name, $document),
        $retained,
      );
    }
    foreach ($document->components ?? [] as $section => $components) {
      foreach ($components as $name => $_) {
        if (!isset($retained[$section . '/' . $name])) {
          unset($components->$name);
        }
      }
      if (!(array) $components) {
        unset($document->components->$section);
      }
    }
    if (isset($document->components) && !(array) $document->components) {
      unset($document->components);
    }
  }

  private function sort(mixed $value): mixed
  {
    if ($value instanceof stdClass) {
      $properties = get_object_vars($value);
      ksort($properties, SORT_STRING);
      foreach ($properties as $key => $property) {
        $properties[$key] = $this->sort($property);
      }
      return (object) $properties;
    }
    return is_array($value) ? array_map($this->sort(...), $value) : $value;
  }
}
