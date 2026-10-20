<?php

namespace app\common\openapi;

use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/** 从完整契约派生可独立分发的模块文件；不改动完整 YAML 字节。 */
final class ArtifactLayout
{
  public static function sourceModule(
    ?string $filename,
    bool $schema = false,
  ): string {
    $filename = str_replace('\\', '/', $filename ?? '');
    $module = 'common';
    if (
      $schema &&
      preg_match('~/([A-Za-z][A-Za-z0-9_]*)Schemas\.php$~', $filename, $match)
    ) {
      $module = strtolower($match[1]);
    } elseif (preg_match('~/app/([^/]+)/~', $filename, $match)) {
      $module = strtolower($match[1]);
    }
    if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $module)) {
      throw new RuntimeException('非法 OpenAPI 来源模块: ' . $module);
    }
    return $module;
  }

  /** @return array<string, string> 相对于输出根目录的确定性文件集合。 */
  public function files(
    array $documents,
    array $pathModules,
    array $schemaModules,
  ): array {
    $files = [];
    $localOperations = [];
    foreach ($documents as $audience => $bytes) {
      $document = Yaml::parse($bytes, Yaml::PARSE_OBJECT_FOR_MAP);
      $fragments = [];
      $locations = [];
      foreach ($document->paths as $path => $_) {
        $module =
          $pathModules[$audience][$path] ??
          throw new RuntimeException('缺少路径来源模块: ' . $path);
        $locations['#/paths/' . $this->escape($path)] =
          'paths/' . $module . '.yaml#/' . $this->escape($path);
      }
      foreach ($document->components->schemas ?? [] as $name => $_) {
        $module =
          $schemaModules[$name] ??
          throw new RuntimeException('缺少 schema 来源模块: ' . $name);
        $locations['#/components/schemas/' . $this->escape($name)] =
          'schemas/' . $module . '.yaml#/' . $this->escape($name);
      }
      // 先迁移入口保留部分的引用，再将已提取的槽位替换成索引。
      $entryFile = $audience . '/openapi.yaml';
      $localOperations[$entryFile] = [];
      $entry = $this->relocate(
        $document,
        $document,
        $locations,
        $localOperations[$entryFile],
        true,
      );
      foreach ($document->paths as $path => $item) {
        $module =
          $pathModules[$audience][$path] ??
          throw new RuntimeException('缺少路径来源模块: ' . $path);
        $file = 'paths/' . $module . '.yaml';
        $localOperations[$audience . '/' . $file] ??= [];
        $fragments[$file][$path] = $this->relocate(
          $item,
          $document,
          $locations,
          $localOperations[$audience . '/' . $file],
          false,
          'pathItem',
        );
        $entry->paths->$path = (object) [
          '$ref' => './' . $file . '#/' . $this->escape($path),
        ];
      }
      foreach ($document->components->schemas ?? [] as $name => $schema) {
        $module =
          $schemaModules[$name] ??
          throw new RuntimeException('缺少 schema 来源模块: ' . $name);
        $file = 'schemas/' . $module . '.yaml';
        $localOperations[$audience . '/' . $file] ??= [];
        $fragments[$file][$name] = $this->relocate(
          $schema,
          $document,
          $locations,
          $localOperations[$audience . '/' . $file],
          false,
          'data',
        );
        $entry->components->schemas->$name = (object) [
          '$ref' => './' . $file . '#/' . $this->escape($name),
        ];
      }
      $files[$audience . '/openapi.yaml'] = $this->yaml($entry);
      foreach ($fragments as $file => $values) {
        ksort($values, SORT_STRING);
        $files[$audience . '/' . $file] = $this->yaml((object) $values);
      }
      $files['generated/openapi.' . $audience . '.yaml'] = $bytes;
    }
    ksort($files, SORT_STRING);
    $this->validate($files, $localOperations);
    return $files;
  }

  private function escape(string $value): string
  {
    return str_replace(['~', '/'], ['~0', '~1'], $value);
  }

  private function yaml(mixed $value): string
  {
    return Yaml::dump(
      $value,
      30,
      2,
      Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
    );
  }

  /** 片段通过同版本入口索引引用组件，保留递归与跨模块引用。 */
  private function relocate(
    mixed $node,
    stdClass $document,
    array $locations,
    array &$localOperations,
    bool $entry = false,
    string $context = 'document',
  ): mixed {
    if (!($node instanceof stdClass) && !is_array($node)) {
      return $node;
    }
    $result = [];
    foreach ($node as $key => $value) {
      if ($key === '$ref') {
        $result[$key] = $this->reference($value, $locations, $entry);
      } elseif (
        $key === 'operationRef' &&
        $context === 'link' &&
        is_string($value) &&
        str_starts_with($value, '#/')
      ) {
        $result[$key] = $this->reference($value, $locations, $entry);
        $localOperations[$result[$key]] = true;
      } elseif (
        $key === 'discriminator' &&
        $value instanceof stdClass &&
        isset($value->mapping)
      ) {
        $value = clone $value;
        $value->mapping = clone $value->mapping;
        foreach ($value->mapping as $name => $reference) {
          if (isset($document->components->schemas->$reference)) {
            $reference = '#/components/schemas/' . $this->escape($reference);
          }
          $value->mapping->$name = $this->reference(
            $reference,
            $locations,
            $entry,
          );
        }
        $result[$key] = $value;
      } else {
        $result[$key] = $this->relocate(
          $value,
          $document,
          $locations,
          $localOperations,
          $entry,
          $this->childContext($context, (string) $key),
        );
      }
    }
    return $node instanceof stdClass ? (object) $result : $result;
  }

  /** 只在 OpenAPI Link 对象内解释 operationRef，示例与请求数据保持原样。 */
  private function childContext(string $context, string $key): string
  {
    return match (true) {
      $context === 'document' && $key === 'paths' => 'pathMap',
      $context === 'document' && $key === 'components' => 'components',
      $context === 'components' && $key === 'links' => 'linkMap',
      $context === 'components' && $key === 'responses' => 'responseMap',
      $context === 'components' && $key === 'callbacks' => 'callbackMap',
      $context === 'pathMap' => 'pathItem',
      $context === 'pathItem' &&
        in_array(
          $key,
          ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'],
          true,
        )
        => 'operation',
      $context === 'operation' && $key === 'responses' => 'responseMap',
      $context === 'operation' && $key === 'callbacks' => 'callbackMap',
      $context === 'callbackMap' => 'callback',
      $context === 'callback' && !str_starts_with($key, 'x-') && $key !== '$ref'
        => 'pathItem',
      $context === 'responseMap' => 'response',
      $context === 'response' && $key === 'links' => 'linkMap',
      $context === 'linkMap' => 'link',
      default => 'data',
    };
  }

  private function reference(
    string $reference,
    array $locations,
    bool $entry,
  ): string {
    // JSON Pointer 不会穿过 $ref 索引；任何已提取槽位的子节点都直接指向定义文件。
    foreach ($locations as $pointer => $location) {
      if (str_starts_with($reference, $pointer . '/')) {
        return ($entry ? './' : '../') .
          $location .
          substr($reference, strlen($pointer));
      }
    }
    return $entry ? $reference : '../openapi.yaml' . $reference;
  }

  /** 校验派生引用图，不展开节点，因此递归 schema 不会无限遍历。 */
  private function validate(array $files, array $localOperations): void
  {
    $parsed = [];
    foreach ($files as $file => $bytes) {
      $parsed[$file] = Yaml::parse($bytes, Yaml::PARSE_OBJECT_FOR_MAP);
    }
    $visit = function (mixed $node, string $file, string $context) use (
      &$visit,
      $parsed,
      $localOperations,
    ): void {
      if (!($node instanceof stdClass) && !is_array($node)) {
        return;
      }
      foreach ($node as $key => $value) {
        $references = $key === '$ref' ? [$value] : [];
        if (
          $key === 'operationRef' &&
          $context === 'link' &&
          is_string($value) &&
          (str_starts_with($value, '#/') ||
            isset($localOperations[$file][$value]))
        ) {
          $references[] = $value;
        }
        if ($key === 'discriminator' && $value instanceof stdClass) {
          $references = array_values((array) ($value->mapping ?? []));
        }
        foreach ($references as $reference) {
          if (!str_contains($reference, '#/')) {
            // 完整文档中的 discriminator 允许 schema 名称简写。
            if (isset($parsed[$file]->components->schemas->$reference)) {
              continue;
            }
            throw new RuntimeException('非法模块引用: ' . $reference);
          }
          [$relative, $pointer] = explode('#/', $reference, 2);
          $parts = explode('/', dirname($file) . '/' . $relative);
          $resolved = [];
          foreach ($parts as $part) {
            if ($part === '..') {
              array_pop($resolved);
            } elseif ($part !== '.' && $part !== '') {
              $resolved[] = $part;
            }
          }
          $targetFile = $relative === '' ? $file : implode('/', $resolved);
          if (
            !isset($parsed[$targetFile]) ||
            explode('/', $targetFile)[0] !== explode('/', $file)[0]
          ) {
            throw new RuntimeException('模块引用越界或缺失: ' . $reference);
          }
          $target = $parsed[$targetFile];
          foreach (explode('/', $pointer) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
            if (
              (!($target instanceof stdClass) && !is_array($target)) ||
              !array_key_exists($part, (array) $target)
            ) {
              throw new RuntimeException('悬空模块引用: ' . $reference);
            }
            $target = is_array($target) ? $target[$part] : $target->$part;
          }
        }
        $visit($value, $file, $this->childContext($context, (string) $key));
      }
    };
    foreach ($parsed as $file => $document) {
      $visit(
        $document,
        $file,
        str_contains($file, '/paths/')
          ? 'pathMap'
          : (str_contains($file, '/schemas/')
            ? 'data'
            : 'document'),
      );
    }
  }
}
