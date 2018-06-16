<?hh // strict
/*
 *  Copyright (c) 2017-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

namespace Facebook\HHAST\__Private;

use type Facebook\TypeAssert\TypeAssert;
use namespace Facebook\HHAST\Linters;
use namespace HH\Lib\{C, Dict, Str, Vec};

use type Facebook\CLILib\{
  CLIWithArguments,
  ExitException,
};
use namespace Facebook\CLILib\CLIOptions;

final class LinterCLI extends CLIWithArguments {
  private bool $xhprof = false;
  private LinterCLIMode $mode = LinterCLIMode::PLAIN;


  use CLIWithVerbosityTrait;

  <<__Override>>
  public static function getHelpTextForOptionalArguments(): string {
    return 'PATH';
  }

  <<__Override>>
  protected function getSupportedOptions(): vec<CLIOptions\CLIOption> {
    return vec[
      CLIOptions\flag(
        () ==> {
          throw new ExitException(1, "--perf is no longer supported; consider --xhprof");
        },
        '[unsupported]',
        '--perf',
      ),
      CLIOptions\flag(
        () ==> {
          $this->xhprof = true;
        },
        'Enable XHProf profiling',
        '--xhprof',
      ),
      CLIOptions\with_required_enum(
        LinterCLIMode::class,
        $m ==> { $this->mode = $m; },
        'Set the output mode; supported values are '.
        Str\join(LinterCLIMode::getValues(), ' | '),
        '--mode',
        '-m',
      ),
      $this->getVerbosityOption(),
    ];
  }

  private function lintFile(
    LinterCLIConfig $config,
    string $path,
    LinterCLIErrorHandler $error_handler,
  ): void {
    $this->verbosePrintf(1, "Linting %s...\n", $path);

    $all_errors = vec[];
    $config = $config->getConfigForFile($path);

    foreach ($config['linters'] as $class) {
      $this->verbosePrintf(2, " - %s\n", $class);

      if (!$class::shouldLintFile($path)) {
        continue;
      }

      $linter = new $class($path);

      if ($linter->isLinterSuppressedForFile()) {
        continue;
      }

      $errors = $linter->getLintErrors();
      $error_handler->processErrors($linter, $config, $errors);
    }
  }

  private function lintDirectory(
    LinterCLIConfig $config,
    string $path,
    LinterCLIErrorHandler $error_handler,
  ): void {
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path),
    );
    foreach ($it as $info) {
      if (!$info->isFile()) {
        continue;
      }
      $ext = Str\lowercase($info->getExtension());
      if ($ext === 'hh' || $ext === 'php') {
        $file = $info->getPathname();
        $this->lintFile($config, $file, $error_handler);
      }
    }
  }

  private function lintPath(
    LinterCLIConfig $config,
    string $path,
    LinterCLIErrorHandler $error_handler,
  ): void {
    if (\is_file($path)) {
      $this->lintFile($config, $path, $error_handler);
    } else if (\is_dir($path)) {
      $this->lintDirectory($config, $path, $error_handler);
    } else {
      throw new ExitException(
        1,
        Str\format("'%s' doesn't appear to be a file or directory, bailing", $path),
      );
    }
  }

  <<__Override>>
  public async function mainAsync(): Awaitable<int> {
    if ($this->xhprof) {
      XHProf::enable();
    }

    $result = await $this->mainImplAsync();

    if ($this->xhprof) {
      XHProf::disableAndDump(\STDERR);
    }

    return $result;
  }

  private async function mainImplAsync(): Awaitable<int> {
    $err = $this->getStderr();
    $roots = $this->getArguments();
    if (C\is_empty($roots)) {
      $config = LinterCLIConfig::getForPath(\getcwd());
      $roots = $config->getRoots();
      if (C\is_empty($roots)) {
        $err->write(
          "You must either specify PATH arguments, or provide a configuration".
          "file.\n",
        );
        return 1;
      }
    } else {
      foreach ($roots as $root) {
        $path = \realpath($root);
        if (\is_dir($path)) {
          $config_file = $path.'/hhast-lint.json';
          if (\file_exists($config_file)) {
            $this->getStdout()->write(
              "Warning: PATH arguments contain a hhast-lint.json, ".
              "which modifies the linters used and customizes behavior. ".
              "Consider 'cd ".
              $root.
              "; vendor/bin/hhast-lint'\n\n",
            );
          }
        }
      }
      $config = null;
    }

    switch ($this->mode) {
      case LinterCLIMode::PLAIN:
        $error_handler = new LinterCLIErrorHandlerPlain($this->getTerminal());
        break;
      case LinterCLIMode::JSON:
        $error_handler = new LinterCLIErrorHandlerJSON($this->getTerminal());
        break;
    }

    foreach ($roots as $root) {
      $root_config = $config ?? LinterCLIConfig::getForPath($root);
      $this->lintPath($root_config, $root, $error_handler);
    }

    $error_handler->print();
    return $error_handler->hadErrors() ? 2 : 0;
  }
}
