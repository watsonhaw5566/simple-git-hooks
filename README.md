# simple-git-hooks

一个零依赖的 PHP 工具，用于在项目中快速配置 git hooks。灵感来自 npm 包 [simple-git-hooks](https://www.npmjs.com/package/simple-git-hooks)。

- **零依赖**：除 PHP 本身外不依赖任何第三方库
- **配置简单**：在 `composer.json` 或独立 json 文件中声明 hooks 即可
- **自动安装**：`composer install` 后自动写入 git hooks
- **全量支持**：覆盖全部 28 个标准 git hook

## 目录

- [安装](#安装)
- [快速开始](#快速开始)
- [配置方式](#配置方式)
  - [composer.json](#方式一composerjson)
  - [独立配置文件](#方式二独立配置文件)
  - [自定义配置路径](#方式三自定义配置路径)
- [可用的 git hooks](#可用的-git-hooks)
- [preserveUnused](#preserveunused)
- [环境变量](#环境变量)
- [卸载](#卸载)
- [常见问题](#常见问题)
- [License](#license)

## 安装

通过 Composer 作为开发依赖安装：

```bash
composer require --dev watsonhaw/simple-git-hooks
```

安装完成后，`post-install-cmd` 脚本会自动根据配置写入 git hooks。

## 快速开始

1. 在 `composer.json` 的 `extra` 中添加 `simple-git-hooks` 配置：

```json
{
    "require-dev": {
        "watsonhaw/simple-git-hooks": "*"
    },
    "extra": {
        "simple-git-hooks": {
            "pre-commit": "vendor/bin/php-cs-fixer fix --dry-run",
            "pre-push": "composer test"
        }
    }
}
```

2. 运行安装命令（如果 `composer install` 时未自动执行）：

```bash
vendor/bin/simple-git-hooks
```

现在每次 `git commit` 会先运行 `php-cs-fixer`，`git push` 前会运行测试。如果 hook 执行失败（退出码非 0），对应的 git 操作会被中止。

## 配置方式

### 方式一：composer.json

在 `composer.json` 的 `extra` 字段中添加 `simple-git-hooks` 配置。`extra` 是 Composer 官方约定的第三方包配置存放位置：

```json
{
    "extra": {
        "simple-git-hooks": {
            "pre-commit": "vendor/bin/php-cs-fixer fix --dry-run",
            "commit-msg": "grep -q '^feat' $1",
            "pre-push": "composer test"
        }
    }
}
```

### 方式二：独立配置文件

在项目根目录创建以下任一文件（优先级从高到低）：

1. `.simple-git-hooks.json`
2. `simple-git-hooks.json`

```json
{
    "pre-commit": "vendor/bin/php-cs-fixer fix --dry-run",
    "pre-push": "composer test"
}
```

当存在独立配置文件时，`composer.json` 中的 `simple-git-hooks` 配置将被忽略。

### 方式三：自定义配置路径

通过 CLI 参数指定任意配置文件：

```bash
vendor/bin/simple-git-hooks ./my-hooks.json
```

## 可用的 git hooks

支持全部标准 git hook：

```
applypatch-msg      pre-applypatch      post-applypatch
pre-commit          pre-merge-commit    prepare-commit-msg
commit-msg          post-commit         pre-rebase
post-checkout       post-merge          pre-push
pre-receive         update              proc-receive
post-receive        post-update         reference-transaction
push-to-checkout    pre-auto-gc         post-rewrite
sendemail-validate  fsmonitor-watchman  p4-changelist
p4-prepare-changelist p4-post-changelist p4-pre-submit
post-index-change
```

## preserveUnused

默认情况下，运行 `simple-git-hooks` 会删除所有未在配置中声明的 hook（避免遗留的旧 hook 继续执行）。如果需要保留某些 hook，可以使用 `preserveUnused`：

保留指定的未配置 hook：

```json
{
    "simple-git-hooks": {
        "pre-commit": "echo commit",
        "preserveUnused": ["commit-msg", "post-commit"]
    }
}
```

保留全部未配置的 hook：

```json
{
    "simple-git-hooks": {
        "pre-commit": "echo commit",
        "preserveUnused": true
    }
}
```

## 环境变量

| 变量 | 说明 |
|------|------|
| `SKIP_INSTALL_SIMPLE_GIT_HOOKS=1` | 跳过 hook 的安装（CI 环境常用） |
| `SKIP_SIMPLE_GIT_HOOKS=1` | 跳过单次 git 操作的 hook 执行 |
| `SIMPLE_GIT_HOOKS_RC` | 指定一个 shell 脚本路径，在每个 hook 执行前 source（用于 nvm / mise 等版本管理器初始化 PATH） |

示例：

```bash
# CI 环境跳过安装
SKIP_INSTALL_SIMPLE_GIT_HOOKS=1 composer install

# 临时跳过某次提交的 hook
SKIP_SIMPLE_GIT_HOOKS=1 git commit -m "wip"

# 或使用 git 原生参数
git commit -m "wip" --no-verify
```

### SIMPLE_GIT_HOOKS_RC 用法

在 GUI git 客户端中，由于不加载 shell 配置文件，可能出现 `command not found`。可以创建一个 init 脚本：

```sh
# ~/.simple-git-hooks.rc
export PATH="$HOME/.local/share/mise/shims:$PATH"
```

然后设置环境变量：

```sh
# ~/.zshenv
export SIMPLE_GIT_HOOKS_RC="$HOME/.simple-git-hooks.rc"
```

## 卸载

移除所有已安装的 git hooks：

```bash
vendor/bin/simple-git-hooks uninstall
```

> 注意：卸载会删除 `.git/hooks` 下所有由本工具管理的 hook 文件。

## 常见问题

### Hooks 没有自动安装？

`simple-git-hooks` 通过 `post-install-cmd` / `post-update-cmd` 自动安装。如果未生效，手动运行一次：

```bash
vendor/bin/simple-git-hooks
```

### 从其他工具（如 husky / cghooks）迁移后 hooks 不执行？

其他工具可能修改了 `core.hooksPath`。检查并重置：

```bash
git config core.hooksPath
# 如果输出不是 .git/hooks，执行：
git config --unset core.hooksPath
```

### 想跳过某个 hook？

- 单次操作：`git commit --no-verify` 或 `SKIP_SIMPLE_GIT_HOOKS=1 git commit`
- 整个会话：`export SKIP_SIMPLE_GIT_HOOKS=1`

### 配置修改后 hooks 没有更新？

每次修改配置后需要重新运行安装命令：

```bash
vendor/bin/simple-git-hooks
```

## License

MIT
