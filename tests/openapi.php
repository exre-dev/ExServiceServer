<?php

namespace app\common\openapi {
  /** 测试进程内的文件系统故障替身：仅指定的一次 rename 失败，其余操作使用真实文件。 */
  function rename(string $from, string $to): bool
  {
    if (
      ($GLOBALS['openapiRenameFailure'] ?? null) ===
        str_replace('\\', '/', $to) ||
      ($GLOBALS['openapiRenameFailureFrom'] ?? null) ===
        str_replace('\\', '/', $from)
    ) {
      unset(
        $GLOBALS['openapiRenameFailure'],
        $GLOBALS['openapiRenameFailureFrom'],
      );
      return false;
    }
    return \rename($from, $to);
  }
}

namespace {
  // 仅使用临时 PHP 元数据与实际 Webman 路由，不加载业务启动文件或 .env。
  require dirname(__DIR__) . '/vendor/autoload.php';

  use app\common\openapi\Generator;
  use app\common\openapi\ArtifactPublisher;
  use Symfony\Component\Yaml\Yaml;
  use Webman\Route;

  function verify(bool $condition, string $message): void
  {
    if (!$condition) {
      throw new RuntimeException($message);
    }
  }

  function rejected(callable $action, string $message): void
  {
    try {
      $action();
    } catch (RuntimeException $exception) {
      verify(
        str_contains($exception->getMessage(), $message),
        $exception->getMessage(),
      );
      return;
    }
    throw new RuntimeException('预期失败: ' . $message);
  }

  /** 仅清理本次测试独占的临时目录，拒绝符号链接与目录越界。 */
  function removeFixture(string $path, string $root): void
  {
    verify(
      str_starts_with(
        str_replace('\\', '/', $path),
        str_replace('\\', '/', $root) . '/',
      ) || $path === $root,
      '清理路径越界',
    );
    verify(!is_link($path), '测试清理不得跟随符号链接');
    if (is_dir($path)) {
      foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
          removeFixture($entry->getPathname(), $root);
        }
      }
      rmdir($path);
    } elseif (file_exists($path)) {
      unlink($path);
    }
  }

  function snapshot(string $root): array
  {
    $files = [];
    if (is_dir($root)) {
      foreach (
        new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        )
        as $entry
      ) {
        verify($entry->isFile() && !$entry->isLink(), '意外的测试文件类型');
        $files[
          str_replace(
            '\\',
            '/',
            substr($entry->getPathname(), strlen($root) + 1),
          )
        ] = file_get_contents($entry->getPathname());
      }
    }
    ksort($files);
    return $files;
  }

  /** 独立重组器：只读取当前 audience 子目录；组件引用保留为规范内引用以终止递归。 */
  function combineFixture(string $directory): array
  {
    $root = realpath($directory);
    $entry = realpath($directory . '/openapi.yaml');
    $index = [];
    foreach (
      Yaml::parse(file_get_contents($entry))['components']['schemas'] ?? []
      as $name => $schema
    ) {
      [$relative, $pointer] = explode('#', $schema['$ref'], 2);
      $index[realpath($directory . '/' . $relative) . '#' . $pointer] =
        '#/components/schemas/' . str_replace(['~', '/'], ['~0', '~1'], $name);
    }
    foreach (
      Yaml::parse(file_get_contents($entry))['paths']
      as $path => $item
    ) {
      [$relative, $pointer] = explode('#', $item['$ref'], 2);
      $index[realpath($directory . '/' . $relative) . '#' . $pointer] =
        '#/paths/' . str_replace(['~', '/'], ['~0', '~1'], $path);
    }
    $walk = function (mixed $value, string $file) use (
      &$walk,
      $root,
      $entry,
      $index,
    ): mixed {
      if (!is_array($value)) {
        return $value;
      }
      $reference = function (string $ref) use ($file, $root): array {
        [$relative, $pointer] = explode('#', $ref, 2);
        $targetFile =
          $relative === '' ? $file : realpath(dirname($file) . '/' . $relative);
        verify(
          is_string($targetFile) &&
            str_starts_with($targetFile, $root . DIRECTORY_SEPARATOR),
          '引用不能依赖 audience 外文件: ' . $ref,
        );
        $target = Yaml::parse(file_get_contents($targetFile));
        foreach (explode('/', substr($pointer, 1)) as $part) {
          $part = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
          verify(
            is_array($target) && array_key_exists($part, $target),
            '重组发现悬空引用: ' . $ref,
          );
          $target = $target[$part];
        }
        return [$targetFile, $pointer, $target];
      };
      if (isset($value['$ref'])) {
        [$targetFile, $pointer, $target] = $reference($value['$ref']);
        if (
          $targetFile !== $entry &&
          $file === $entry &&
          isset($index[$targetFile . '#' . $pointer])
        ) {
          return $walk($target, $targetFile);
        }
        $value['$ref'] = '#' . $pointer;
        if ($targetFile !== $entry) {
          $reference = $targetFile . '#' . $pointer;
          $found = false;
          foreach ($index as $prefix => $canonical) {
            if (
              $reference === $prefix ||
              str_starts_with($reference, $prefix . '/')
            ) {
              $value['$ref'] = $canonical . substr($reference, strlen($prefix));
              $found = true;
              break;
            }
          }
          verify($found, '片段引用缺少入口索引');
        }
      }
      if (isset($value['discriminator']['mapping'])) {
        foreach ($value['discriminator']['mapping'] as $name => $ref) {
          [$targetFile, $pointer] = $reference($ref);
          verify($targetFile === $entry, 'discriminator 必须指向当前入口');
          $value['discriminator']['mapping'][$name] = '#' . $pointer;
        }
      }
      if (
        isset($value['operationRef']) &&
        (str_starts_with($value['operationRef'], './paths/') ||
          str_starts_with($value['operationRef'], '../paths/'))
      ) {
        [$targetFile, $pointer, $target] = $reference($value['operationRef']);
        verify(
          isset($target['operationId']),
          'Link operationRef 必须解析到操作',
        );
        $resolved = $targetFile . '#' . $pointer;
        $matched = false;
        foreach ($index as $prefix => $canonical) {
          if (str_starts_with($resolved, $prefix . '/')) {
            $value['operationRef'] =
              $canonical . substr($resolved, strlen($prefix));
            $matched = true;
            break;
          }
        }
        verify($matched, 'Link operationRef 缺少入口路径索引');
      }
      foreach ($value as $key => $child) {
        $value[$key] = $walk($child, $file);
      }
      return $value;
    };
    return $walk(Yaml::parse(file_get_contents($entry)), $entry);
  }

  function normalizeMappings(array $document): array
  {
    foreach ($document['components']['schemas'] ?? [] as $name => $schema) {
      foreach (
        $schema['discriminator']['mapping'] ?? []
        as $key => $reference
      ) {
        if (isset($document['components']['schemas'][$reference])) {
          $document['components']['schemas'][$name]['discriminator']['mapping'][
            $key
          ] =
            '#/components/schemas/' .
            str_replace(['~', '/'], ['~0', '~1'], $reference);
        }
      }
    }
    return $document;
  }

  $temporary = sys_get_temp_dir() . '/exre-openapi-' . bin2hex(random_bytes(8));
  mkdir($temporary);
  try {
    verify(class_exists(Generator::class), '缺少 OpenAPI CLI 生成器');
    $sequence = 0;
    $fixture = function (string $change = '') use (
      $temporary,
      &$sequence,
    ): Generator {
      $namespace = 'OpenapiFixture' . ++$sequence;
      $source = <<<'PHP'
      <?php
      namespace NAMESPACE;
      use OpenApi\Attributes as OA;
      #[OA\Info(title: '临时测试', version: '1')]
      #[OA\SecurityScheme(securityScheme: 'sessionAuth', type: 'http', scheme: 'bearer')]
      #[OA\Components(links: [new OA\Link(link: 'ReusableTarget', operationRef: '#/paths/~1fixture~1shared/get')])]
      #[OA\Response(response: 'SharedResponse', description: '复用的嵌套 schema 引用', content: new OA\JsonContent(ref: '#/components/schemas/Shared/properties/odd~1~0'))]
      #[OA\Response(response: 'PathResponse', description: '复用的嵌套 path 引用', content: new OA\JsonContent(ref: '#/paths/~1fixture~1public/get/responses/200/content/application~1json/schema'))]
      #[OA\Schema(schema: 'Shared', type: 'object', properties: [new OA\Property(property: 'msg', type: 'string'), new OA\Property(property: 'odd/~', type: 'string'), new OA\Property(property: 'child', ref: '#/components/schemas/Shared'), new OA\Property(property: 'peer', ref: '#/components/schemas/SharedPeer')])]
      #[OA\Schema(schema: 'SharedPeer', type: 'object', properties: [new OA\Property(property: 'back', ref: '#/components/schemas/Shared')])]
      #[OA\Schema(schema: 'PublicData', type: 'object', properties: [new OA\Property(property: 'shared', ref: '#/components/schemas/Shared'), new OA\Property(property: 'escaped', ref: '#/components/schemas/Shared/properties/odd~1~0')], discriminator: new OA\Discriminator(propertyName: 'kind', mapping: ['mapped' => '#/components/schemas/Mapped', 'named' => 'Mapped']))]
      #[OA\Schema(schema: 'Mapped', type: 'object', properties: [new OA\Property(property: 'kind', type: 'string')])]
      #[OA\Schema(schema: 'InternalData', type: 'object', properties: [new OA\Property(property: 'shared', ref: '#/components/schemas/Shared')])]
      #[OA\Schema(schema: 'Unused', type: 'string')]
      class Controller
      {
          #[OA\Get(path: '/fixture/public', operationId: 'fixturePublic', responses: [new OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PublicData', example: ['operationRef' => '#/paths/literal-example', 'links' => ['ordinary' => ['operationRef' => '#/literal']]]), links: [new OA\Link(link: 'target', operationRef: '#/paths/~1fixture~1shared/get'), new OA\Link(link: 'external', operationRef: 'https://example.invalid/openapi.yaml#/paths/~1external/get'), new OA\Link(link: 'relativeExternal', operationRef: 'other.yaml#/paths/~1external/get'), new OA\Link(link: 'reused', ref: '#/components/links/ReusableTarget')]), new OA\Response(response: 201, ref: '#/components/responses/SharedResponse')], x: ['audience' => 'public'])]
          public function first() {}
          #[OA\Post(path: '/fixture/internal', operationId: 'fixtureInternal', security: [['sessionAuth' => []]], responses: [new OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/InternalData'))], x: ['audience' => 'internal'])]
          public function second() {}
          #[OA\Get(path: '/fixture/shared', operationId: 'fixtureSharedPublic', responses: [new OA\Response(response: 204, description: '成功'), new OA\Response(response: 200, ref: '#/components/responses/PathResponse')], x: ['audience' => 'public'])]
          public function sharedPublic() {}
          #[OA\Post(path: '/fixture/shared', operationId: 'fixtureSharedInternal', responses: [new OA\Response(response: 204, description: '成功')], x: ['audience' => 'internal'])]
          public function sharedInternal() {}
      }
      PHP;
      $source = str_replace('NAMESPACE', $namespace, $source);
      $source = match ($change) {
        'missing-audience' => str_replace(
          ", x: ['audience' => 'public']",
          '',
          $source,
        ),
        'unknown-audience' => str_replace(
          "'audience' => 'public'",
          "'audience' => 'partner'",
          $source,
        ),
        'duplicate-id' => str_replace(
          "operationId: 'fixtureInternal'",
          "operationId: 'fixturePublic'",
          $source,
        ),
        'missing-id' => str_replace(
          "operationId: 'fixtureInternal', ",
          '',
          $source,
        ),
        'dangling-ref' => str_replace(
          '#/components/schemas/PublicData',
          '#/components/schemas/Absent',
          $source,
        ),
        'dangling-security' => str_replace(
          "security: [['sessionAuth'",
          "security: [['absentAuth'",
          $source,
        ),
        'dangling-link' => str_replace(
          '#/paths/~1fixture~1shared/get',
          '#/paths/~1fixture~1absent/get',
          $source,
        ),
        default => $source,
      };
      $directory = $temporary . '/' . $namespace . '/app/fixture';
      mkdir($directory, 0777, true);
      $sources = [];
      preg_match_all('/^#\[OA\\\\Schema.*$/m', $source, $matches);
      foreach ($matches[0] as $attribute) {
        preg_match("/schema: '([^']+)'/", $attribute, $match);
        $group = match ($match[1]) {
          'Shared', 'SharedPeer' => 'Document',
          'PublicData' => $change === 'different-module'
            ? 'PartnerSchemas'
            : 'PublicSchemas',
          'Mapped' => 'MappingSchemas',
          default => $match[1] . 'Schemas',
        };
        $sources[$group][] = $attribute;
        $source = str_replace($attribute . "\n", '', $source);
      }
      $scan = [];
      $schemaDirectory = $temporary . '/' . $namespace . '/app/common/openapi';
      mkdir($schemaDirectory, 0777, true);
      foreach ($sources as $group => $attributes) {
        $schemaFile = $schemaDirectory . '/' . $group . '.php';
        file_put_contents(
          $schemaFile,
          '<?php namespace ' .
            $namespace .
            '; use OpenApi\\Attributes as OA;' .
            "\n" .
            implode("\n", $attributes) .
            "\nclass " .
            $group .
            ' {}',
        );
        require $schemaFile;
        $scan[] = $schemaFile;
      }
      // 同路径的另一个 audience 由另一个 Controller 模块定义，必须各自按来源分组。
      $secondary = [];
      foreach (explode("\n", $source) as $line) {
        if (
          str_contains($line, "operationId: 'fixtureSharedInternal'") ||
          str_contains($line, 'public function sharedInternal()')
        ) {
          $secondary[] = $line;
          $source = str_replace($line . "\n", '', $source);
        }
      }
      $secondaryDirectory = $temporary . '/' . $namespace . '/app/secondary';
      mkdir($secondaryDirectory);
      $secondaryFile = $secondaryDirectory . '/SecondaryController.php';
      file_put_contents(
        $secondaryFile,
        '<?php namespace ' .
          $namespace .
          '; use OpenApi\\Attributes as OA; class SecondaryController {' .
          "\n" .
          implode("\n", $secondary) .
          "\n}",
      );
      require $secondaryFile;
      $scan[] = $secondaryFile;
      $file = $directory . '/FixtureController.php';
      file_put_contents($file, $source);
      require $file;
      return new Generator([
        'scan_path' => array_merge([$file], $scan),
        'public_operations' =>
          $change === 'wrong-policy'
            ? []
            : ['GET /fixture/public', 'GET /fixture/shared'],
      ]);
    };
    $generator = $fixture();
    // 使用框架路由装载入口验证真实分组路径，不从 PHP 文本提取路由。
    file_put_contents(
      $temporary . '/route.php',
      <<<'PHP'
      <?php
      \Webman\Route::disableDefaultRoute();
      \Webman\Route::group('/fixture', function () {
          \Webman\Route::get('/public', [\OpenapiFixture1\Controller::class, 'first']);
          \Webman\Route::post('/internal', [\OpenapiFixture1\Controller::class, 'second']);
          \Webman\Route::get('/shared', [\OpenapiFixture1\Controller::class, 'sharedPublic']);
          \Webman\Route::post('/shared', [\OpenapiFixture1\SecondaryController::class, 'sharedInternal']);
      });
      PHP
      ,
    );
    Route::load([$temporary]);
    verify(Route::isDefaultRouteDisabled(), '默认路由必须关闭');
    $routes = Route::getRoutes();
    $documents = $generator->generate($routes);
    verify(
      array_keys($documents) === ['public', 'internal'],
      '必须生成两个 audience',
    );
    $public = Yaml::parse($documents['public']);
    $internal = Yaml::parse($documents['internal']);
    verify(
      array_keys($public['paths']) === ['/fixture/public', '/fixture/shared'],
      '公开接口过滤失败',
    );
    verify(
      array_keys($internal['paths']) === [
        '/fixture/internal',
        '/fixture/shared',
      ],
      '内部接口过滤失败',
    );
    verify(
      array_keys($public['paths']['/fixture/shared']) === ['get'] &&
        array_keys($internal['paths']['/fixture/shared']) === ['post'],
      '同路径不同 audience 方法未独立过滤',
    );
    verify(
      isset(
        $public['components']['schemas']['Shared'],
        $public['components']['schemas']['PublicData'],
      ),
      '传递引用组件丢失',
    );
    verify(
      !isset($public['components']['schemas']['InternalData']) &&
        !isset($public['components']['schemas']['Unused']),
      '公开组件泄漏',
    );
    verify(
      isset($public['components']['schemas']['Mapped']),
      'discriminator 映射组件丢失',
    );
    verify(
      !isset($internal['components']['schemas']['PublicData']) &&
        !isset($internal['components']['schemas']['Mapped']),
      '内部组件泄漏',
    );
    verify(
      !isset($public['components']['securitySchemes']),
      '未使用鉴权组件未清理',
    );
    verify(
      isset($internal['components']['securitySchemes']['sessionAuth']),
      '安全引用组件丢失',
    );
    verify($documents === $generator->generate($routes), '生成字节不确定');
    foreach (
      [
        'missing-audience' => 'x-audience',
        'unknown-audience' => 'x-audience',
        'duplicate-id' => 'operationId',
        'missing-id' => 'operationId',
        'dangling-ref' => 'ref',
        'dangling-security' => 'ref',
        'wrong-policy' => '归属',
      ]
      as $case => $message
    ) {
      rejected(fn() => $fixture($case)->generate($routes), $message);
    }
    rejected(
      fn() => $generator->generate(array_slice($routes, 0, 1)),
      '多余路由',
    );
    Route::options('/fixture/business-options', function () {});
    rejected(fn() => $generator->generate(Route::getRoutes()), '缺少路由');
    Route::get('/fixture/missing', [
      OpenapiFixture1\Controller::class,
      'first',
    ]);
    rejected(fn() => $generator->generate(Route::getRoutes()), '缺少路由');
    mkdir($temporary . '/api');
    $output = $temporary . '/api';
    rejected(
      fn() => $generator->execute('check', $routes, $temporary . '/absent'),
      '缺失',
    );
    verify(!is_dir($temporary . '/absent'), 'check 不得创建目录');
    $generator->execute('lint', $routes, $temporary . '/absent');
    verify(!is_dir($temporary . '/absent'), 'lint 不得创建目录');
    $collisionOutput = $temporary . '/collision';
    mkdir($collisionOutput . '/public/schemas', 0777, true);
    file_put_contents(
      $collisionOutput . '/public/schemas/common.yaml',
      '已有手写 schema',
    );
    file_put_contents(
      $collisionOutput . '/openapi.public.yaml',
      '可迁移旧文档',
    );
    $collisionBefore = snapshot($collisionOutput);
    rejected(
      fn() => $generator->execute('generate', $routes, $collisionOutput),
      '未受管',
    );
    rejected(
      fn() => $generator->execute('check', $routes, $collisionOutput),
      '未受管',
    );
    verify(
      snapshot($collisionOutput) === $collisionBefore,
      '初次生成碰撞覆盖或接管了非受管文件',
    );
    file_put_contents($output . '/openapi.public.yaml', '旧公开文档');
    file_put_contents($output . '/openapi.internal.yaml', '旧内部文档');
    file_put_contents($output . '/unowned.yaml', '手工文件');
    $generator->execute('generate', $routes, $output);
    verify(is_file($output . '/public/openapi.yaml'), '缺少 public 模块入口');
    verify(
      is_file($output . '/internal/openapi.yaml'),
      '缺少 internal 模块入口',
    );
    verify(
      (glob($output . '/public/paths/*.yaml') ?: []) !== [],
      '缺少 path 模块文件',
    );
    verify(
      (glob($output . '/public/schemas/*.yaml') ?: []) !== [],
      '缺少 schema 模块文件',
    );
    verify(
      file_get_contents($output . '/generated/openapi.public.yaml') ===
        $documents['public'],
      '完整 YAML 字节改变',
    );
    verify(
      !file_exists($output . '/openapi.public.yaml') &&
        !file_exists($output . '/openapi.internal.yaml'),
      '旧根文件未迁移',
    );
    verify(
      file_get_contents($output . '/unowned.yaml') === '手工文件',
      '迁移删除了非受管文件',
    );
    verify(
      file_get_contents($output . '/generated/openapi.internal.yaml') ===
        $documents['internal'],
      '内部完整 YAML 字节改变',
    );
    $expected = [
      '.openapi-manifest.json',
      'generated/openapi.internal.yaml',
      'generated/openapi.public.yaml',
      'internal/openapi.yaml',
      'internal/paths/fixture.yaml',
      'internal/paths/secondary.yaml',
      'internal/schemas/common.yaml',
      'internal/schemas/internaldata.yaml',
      'public/openapi.yaml',
      'public/paths/fixture.yaml',
      'public/schemas/common.yaml',
      'public/schemas/mapping.yaml',
      'public/schemas/public.yaml',
      'unowned.yaml',
    ];
    sort($expected);
    verify(
      array_keys(snapshot($output)) === $expected,
      '产物集合或模块来源分组不正确',
    );
    $entry = Yaml::parse(file_get_contents($output . '/public/openapi.yaml'));
    verify(
      $entry['paths']['/fixture/public']['$ref'] ===
        './paths/fixture.yaml#/~1fixture~1public',
      '入口 path 引用转义错误',
    );
    $internalEntry = Yaml::parse(
      file_get_contents($output . '/internal/openapi.yaml'),
    );
    verify(
      $entry['paths']['/fixture/shared']['$ref'] ===
        './paths/fixture.yaml#/~1fixture~1shared' &&
        $internalEntry['paths']['/fixture/shared']['$ref'] ===
          './paths/secondary.yaml#/~1fixture~1shared',
      '同路径跨 audience 的 Controller 模块分组错误',
    );
    verify(
      $entry['components']['schemas']['PublicData']['$ref'] ===
        './schemas/public.yaml#/PublicData',
      'schema 来源分组错误',
    );
    verify(
      $entry['components']['responses']['SharedResponse']['content'][
        'application/json'
      ]['schema']['$ref'] ===
        './schemas/common.yaml#/Shared/properties/odd~1~0',
      '入口保留组件的嵌套 schema 指针未按入口基址迁移',
    );
    verify(
      $entry['components']['responses']['PathResponse']['content'][
        'application/json'
      ]['schema']['$ref'] ===
        './paths/fixture.yaml#/~1fixture~1public/get/responses/200/content/application~1json/schema',
      '入口保留组件的嵌套 path 指针未按入口基址迁移',
    );
    $pathFragment = Yaml::parse(
      file_get_contents($output . '/public/paths/fixture.yaml'),
    );
    $fixtureResponse =
      $pathFragment['/fixture/public']['get']['responses'][200];
    verify(
      $fixtureResponse['links']['target']['operationRef'] ===
        '../paths/fixture.yaml#/~1fixture~1shared/get',
      '响应 Link operationRef 未按片段基址迁移',
    );
    verify(
      $entry['components']['links']['ReusableTarget']['operationRef'] ===
        './paths/fixture.yaml#/~1fixture~1shared/get',
      '可复用 Link operationRef 未按入口基址迁移',
    );
    verify(
      $fixtureResponse['links']['external']['operationRef'] ===
        'https://example.invalid/openapi.yaml#/paths/~1external/get' &&
        $fixtureResponse['links']['relativeExternal']['operationRef'] ===
          'other.yaml#/paths/~1external/get',
      '外部 Link operationRef 被修改',
    );
    verify(
      $fixtureResponse['content']['application/json']['example'] ===
        $public['paths']['/fixture/public']['get']['responses'][200]['content'][
          'application/json'
        ]['example'],
      '示例数据的 operationRef 同名字段被修改',
    );
    $fragment = Yaml::parse(
      file_get_contents($output . '/public/schemas/public.yaml'),
    );
    verify(
      $fragment['PublicData']['properties']['escaped']['$ref'] ===
        '../schemas/common.yaml#/Shared/properties/odd~1~0',
      '嵌套 JSON Pointer 改变',
    );
    verify(
      $fragment['PublicData']['discriminator']['mapping']['named'] ===
        '../openapi.yaml#/components/schemas/Mapped',
      '名称映射未转换为相对引用',
    );
    verify(
      combineFixture($output . '/public') == normalizeMappings($public),
      '公开拆分再合并语义不等价',
    );
    verify(
      combineFixture($output . '/internal') == normalizeMappings($internal),
      '内部拆分再合并语义不等价',
    );
    $danglingLink = $fixture('dangling-link');
    rejected(
      fn() => $danglingLink->execute(
        'lint',
        $routes,
        $temporary . '/link-lint',
      ),
      '悬空模块引用',
    );
    verify(!is_dir($temporary . '/link-lint'), '悬空 Link 校验不得写盘');
    $before = snapshot($output);
    $generator->execute('generate', $routes, $output);
    verify(snapshot($output) === $before, '全部产物重复生成不确定');
    $generator->execute('check', $routes, $output);
    $fragmentPath = $output . '/public/schemas/public.yaml';
    file_put_contents($fragmentPath, '陈旧产物');
    $tampered = snapshot($output);
    rejected(fn() => $generator->execute('check', $routes, $output), '陈旧');
    verify(snapshot($output) === $tampered, 'check 不得写入');
    $invalid = $fixture('unknown-audience');
    rejected(
      fn() => $invalid->execute('generate', $routes, $output),
      'x-audience',
    );
    rejected(
      fn() => $generator->execute('generate', Route::getRoutes(), $output),
      '缺少路由',
    );
    verify(snapshot($output) === $tampered, '生成失败覆盖了产物');
    $generator->execute('lint', $routes, $output);
    verify(snapshot($output) === $tampered, 'lint 不得写入');
    unlink($fragmentPath);
    $missing = snapshot($output);
    rejected(fn() => $generator->execute('check', $routes, $output), '缺失');
    verify(snapshot($output) === $missing, '缺失片段检查不得写入');
    $generator->execute('generate', $routes, $output);
    // 第二个输出目标无效时，第一个现有产物仍必须保留。
    $later = $output . '/public/schemas/public.yaml';
    unlink($later);
    mkdir($later);
    $failed = snapshot($output);
    try {
      rejected(
        fn() => $generator->execute('generate', $routes, $output),
        '不是文件',
      );
      verify(snapshot($output) === $failed, '输出失败覆盖了另一产物');
      verify(!(glob($output . '/.openapi-stage-*') ?: []), '暂存文件未清理');
    } finally {
      rmdir($later);
    }
    $generator->execute('generate', $routes, $output);
    // 模块迁移仅删除清单中的旧模块，保留同目录内非受管文件。
    file_put_contents($output . '/public/schemas/handwritten.yaml', '自有文件');
    $moved = $fixture('different-module');
    $moveBefore = snapshot($output);
    rejected(fn() => $moved->execute('check', $routes, $output), '陈旧');
    verify(snapshot($output) === $moveBefore, '过期文件检查不得写入');
    file_put_contents(
      $output . '/public/schemas/partner.yaml',
      '新模块目标上的手写内容',
    );
    $newModuleCollision = snapshot($output);
    rejected(fn() => $moved->execute('generate', $routes, $output), '未受管');
    rejected(fn() => $moved->execute('check', $routes, $output), '未受管');
    verify(
      snapshot($output) === $newModuleCollision,
      '新模块碰撞修改了已有清单或文件',
    );
    unlink($output . '/public/schemas/partner.yaml');
    $moved->execute('generate', $routes, $output);
    verify(
      !file_exists($fragmentPath) &&
        is_file($output . '/public/schemas/partner.yaml'),
      '过期模块未受控清理',
    );
    verify(
      file_get_contents($output . '/public/schemas/handwritten.yaml') ===
        '自有文件',
      '清理删除了非受管模块',
    );
    $moved->execute('check', $routes, $output);
    $manifest = $output . '/' . ArtifactPublisher::MANIFEST;
    $validManifest = file_get_contents($manifest);
    file_put_contents($temporary . '/outside.yaml', '边界外文件');
    foreach (
      [
        '../outside.yaml',
        'public/schemas/../../outside.yaml',
        '/absolute.yaml',
        'public/schemas/evil\\file.yaml',
      ]
      as $unsafe
    ) {
      file_put_contents(
        $manifest,
        json_encode(['version' => 1, 'files' => [$unsafe]]),
      );
      $unsafeBefore = snapshot($output);
      rejected(
        fn() => $moved->execute('generate', $routes, $output),
        '清单路径',
      );
      rejected(fn() => $moved->execute('check', $routes, $output), '清单路径');
      verify(
        snapshot($output) === $unsafeBefore &&
          file_get_contents($temporary . '/outside.yaml') === '边界外文件',
        '清单越界产生副作用',
      );
    }
    file_put_contents($manifest, $validManifest);
    $moved->execute('check', $routes, $output);
    // 发布已经替换完整文件与部分片段后，让后续 rename 失败；必须恢复全部原字节与旧清单。
    file_put_contents(
      $output . '/generated/openapi.internal.yaml',
      '发布前的完整文件',
    );
    file_put_contents(
      $output . '/internal/paths/fixture.yaml',
      '发布前的模块文件',
    );
    $rollbackBefore = snapshot($output);
    $GLOBALS['openapiRenameFailure'] = str_replace(
      '\\',
      '/',
      $output . '/public/schemas/public.yaml',
    );
    try {
      rejected(
        fn() => $generator->execute('generate', $routes, $output),
        '无法替换产物',
      );
      verify(
        !isset($GLOBALS['openapiRenameFailure']),
        '未触发真实发布顺序中的故障点',
      );
      verify(
        snapshot($output) === $rollbackBefore,
        '中途发布失败未恢复全部原文件',
      );
      verify(
        !(glob($output . '/.openapi-stage-*') ?: []),
        '回滚未清理本次暂存目录',
      );
    } finally {
      unset($GLOBALS['openapiRenameFailure']);
    }
    $generator->execute('generate', $routes, $output);
    $generator->execute('check', $routes, $output);
    // 清单提交、首个旧模块已移除后故障，也必须恢复清单和已经移除的受管文件。
    $owned = json_decode(
      file_get_contents($manifest),
      true,
      flags: JSON_THROW_ON_ERROR,
    );
    foreach (['obsolete_a', 'obsolete_b'] as $module) {
      $file = 'public/schemas/' . $module . '.yaml';
      $owned['files'][] = $file;
      file_put_contents($output . '/' . $file, $module);
    }
    file_put_contents($manifest, json_encode($owned));
    $staleBefore = snapshot($output);
    rejected(fn() => $generator->execute('check', $routes, $output), '陈旧');
    verify(snapshot($output) === $staleBefore, '过期受管清单检查不得写入');
    $GLOBALS['openapiRenameFailureFrom'] = str_replace(
      '\\',
      '/',
      $output . '/public/schemas/obsolete_b.yaml',
    );
    try {
      rejected(
        fn() => $generator->execute('generate', $routes, $output),
        '无法备份原产物',
      );
      verify(
        !isset($GLOBALS['openapiRenameFailureFrom']),
        '未触发旧文件清理故障',
      );
      verify(snapshot($output) === $staleBefore, '清理失败未恢复旧模块和清单');
    } finally {
      unset($GLOBALS['openapiRenameFailureFrom']);
    }
    $generator->execute('generate', $routes, $output);
    $generator->execute('check', $routes, $output);
    verify(
      !file_exists($output . '/public/schemas/obsolete_a.yaml') &&
        !file_exists($output . '/public/schemas/obsolete_b.yaml'),
      '受管旧文件未清理',
    );
    echo "OpenAPI 工具行为测试通过\n";
  } finally {
    removeFixture($temporary, $temporary);
  }
}
