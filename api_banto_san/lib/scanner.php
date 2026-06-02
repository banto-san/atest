<?php
declare(strict_types=1);

/**
 * スキャナ検出エンジン（仕様書 §6）。
 * --------------------------------------------------------------------------
 * ディレクトリを走査し、SDKインポート / ベースURL / 環境変数名 のパターンで
 * 外部APIの使用箇所を推定する。in-app スキャンと CLI(scan.php) の両方から使う。
 * 誤検出は許容（UI側で無視/マージ）。node_modules / vendor / dist 等は除外。
 */

const SCAN_EXCLUDE_DIRS = [
    'node_modules', 'vendor', '.git', '.svn', '.hg', 'dist', 'build', '.next',
    'bower_components', 'cache', '.cache', 'tmp', 'temp', 'coverage', '.idea', '.vscode',
];
const SCAN_EXTS = [
    'php','js','mjs','cjs','ts','jsx','tsx','vue','svelte','py','rb','go','java','kt',
    'cs','json','yaml','yml','env','html','htm','txt','ini','conf','config','sh','tpl','blade',
];
const SCAN_MAX_FILE_BYTES = 524288;   // 512KB 超はスキップ
const SCAN_MAX_FILES      = 20000;     // 暴走防止

/** providers.json を読み込む */
function load_providers(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return $data['providers'] ?? [];
}

/**
 * $root 配下を走査して検出結果を返す。
 * 戻り値: provider名をキーにした連想配列の values 相当のリスト。
 *   [ ['name'=>, 'provider'=>, 'key_location'=>, 'detected_by'=>[], 'usages'=>[ [repo,file,line,snippet] ]] ]
 *
 * @param array $opts ['repo'=>表示用ラベル, 'max_files'=>int]
 */
function scan_directory(string $root, array $providers, array $opts = []): array
{
    $repo = (string) ($opts['repo'] ?? basename($root));
    $maxFiles = (int) ($opts['max_files'] ?? SCAN_MAX_FILES);
    $secrets = !empty($opts['secrets']);   // .env等のキー値も取り込むか

    $real = realpath($root);
    if ($real === false || !is_dir($real)) {
        throw new RuntimeException('ディレクトリが見つかりません: ' . $root);
    }

    // 検出を provider 名でまとめる
    $found = [];   // name => ['name','provider','key_location','detected_by'=>set, 'usages'=>[]]
    $touch = static function (array &$found, string $name, string $provider, string $docsUrl): void {
        if (!isset($found[$name])) {
            $found[$name] = [
                'name'         => $name,
                'provider'     => $provider,
                'docs_url'     => $docsUrl,
                'key_location' => '',
                'detected_by'  => [],
                'usages'       => [],
            ];
        }
    };

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            static function ($current) {
                /** @var SplFileInfo $current */
                $name = $current->getFilename();
                if ($current->isDir()) {
                    return !in_array($name, SCAN_EXCLUDE_DIRS, true);
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $fileCount = 0;
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        if (++$fileCount > $maxFiles) {
            break;
        }
        $path = $file->getPathname();
        $ext  = strtolower($file->getExtension());
        $base = $file->getFilename();
        $isEnvFile = (strncmp($base, '.env', 4) === 0);
        if (!$isEnvFile && !in_array($ext, SCAN_EXTS, true)) {
            continue;
        }
        if ($file->getSize() > SCAN_MAX_FILE_BYTES) {
            continue;
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            continue;
        }
        // バイナリらしきものは除外
        if (strpos($content, "\0") !== false) {
            continue;
        }

        $rel = ltrim(str_replace($real, '', $path), '/\\');
        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $i => $line) {
            if ($line === '' || strlen($line) > 1000) {
                continue;
            }
            $lower = strtolower($line);
            $lineNo = $i + 1;

            foreach ($providers as $p) {
                $pname    = (string) $p['name'];
                $apiName  = (string) ($p['default_api_name'] ?? $pname);
                $docsUrl  = (string) ($p['docs_url'] ?? '');
                // 環境変数名は sdk/host と独立に判定（env名にsdk名が部分一致しても拾えるように）
                $envName = null;
                foreach (($p['env'] ?? []) as $env) {
                    if ($env !== '' && strpos($line, $env) !== false) { $envName = $env; break; }
                }
                $hit = null;          // 検出タグ（使用箇所記録用）
                foreach (($p['sdk'] ?? []) as $tok) {
                    if ($tok !== '' && stripos($line, $tok) !== false) { $hit = 'sdk:' . $tok; break; }
                }
                if (!$hit) {
                    foreach (($p['host'] ?? []) as $host) {
                        if ($host !== '' && stripos($line, $host) !== false) { $hit = 'host:' . $host; break; }
                    }
                }
                if (!$hit && $envName !== null) { $hit = 'env:' . $envName; }

                if ($hit) {
                    $touch($found, $apiName, $pname, $docsUrl);
                    $found[$apiName]['detected_by'][$hit] = true;
                    if ($envName && $found[$apiName]['key_location'] === '') {
                        $found[$apiName]['key_location'] = 'env: ' . $envName;
                    }
                    if ($secrets && $envName && empty($found[$apiName]['secret'])) {
                        $v = extract_env_value($line, $envName);
                        if ($v !== null) { $found[$apiName]['secret'] = $v; }
                    }
                    add_usage($found[$apiName]['usages'], $repo, $rel, $lineNo, $line);
                }
            }

            // 既知プロバイダに当たらない汎用の *_API_KEY / *_SECRET / *_TOKEN 等
            if (preg_match_all('/\b([A-Z][A-Z0-9]{1,30}(?:_[A-Z0-9]+)*?_(?:API_KEY|APIKEY|SECRET|SECRET_KEY|ACCESS_KEY|ACCESS_TOKEN|AUTH_TOKEN|TOKEN))\b/', $line, $mm)) {
                foreach ($mm[1] as $envVar) {
                    if (is_known_env($providers, $envVar)) {
                        continue;
                    }
                    $prefix = preg_replace('/_(API_KEY|APIKEY|SECRET|SECRET_KEY|ACCESS_KEY|ACCESS_TOKEN|AUTH_TOKEN|TOKEN)$/', '', $envVar);
                    $name = ucfirst(strtolower(str_replace('_', ' ', $prefix))) . '（推定）';
                    $touch($found, $name, $prefix, '');
                    $found[$name]['detected_by']['env:' . $envVar] = true;
                    if ($found[$name]['key_location'] === '') {
                        $found[$name]['key_location'] = 'env: ' . $envVar;
                    }
                    if ($secrets && empty($found[$name]['secret'])) {
                        $v = extract_env_value($line, $envVar);
                        if ($v !== null) { $found[$name]['secret'] = $v; }
                    }
                    add_usage($found[$name]['usages'], $repo, $rel, $lineNo, $line);
                }
            }
        }
    }

    // detected_by を配列へ整形して返す
    $out = [];
    foreach ($found as $api) {
        $api['detected_by'] = array_keys($api['detected_by']);
        $out[] = $api;
    }
    return $out;
}

/** usage を重複排除しつつ追加（同一 file:line は1件、上限あり） */
function add_usage(array &$usages, string $repo, string $file, int $line, string $snippet): void
{
    if (count($usages) >= 500) {
        return;
    }
    foreach ($usages as $u) {
        if ($u['file'] === $file && $u['line'] === $line) {
            return;
        }
    }
    $usages[] = [
        'repo'    => $repo,
        'file'    => $file,
        'line'    => $line,
        'snippet' => redact_secrets(trim(mb_substr($snippet, 0, 240))),
    ];
}

/**
 * スニペット中のキー本体らしき文字列を伏字化する（保存・送信前の安全策・仕様書 §4）。
 * 環境変数名やホスト名は残し、ハードコードされた秘密値だけを *** に置換する。
 * ベストエフォート（完全な秘匿保証ではない）。
 */
function redact_secrets(string $line): string
{
    $line = mb_substr($line, 0, 300);
    // 1) KEY=VALUE / KEY: VALUE 形式（.env など）の右辺値を伏字化
    $line = preg_replace('/^(\s*[A-Za-z_][A-Za-z0-9_.\-]*\s*[:=]\s*)([\'"]?)([^\s\'"]{6,})/', '${1}${2}***', $line);
    // 2) 既知プレフィクスの鍵
    $line = preg_replace(
        '/\b(sk-[A-Za-z0-9_\-]{8,}|sk_(?:live|test)_[A-Za-z0-9]{10,}|pk_(?:live|test)_[A-Za-z0-9]{10,}|rk_[A-Za-z0-9]{8,}|AKIA[0-9A-Z]{12,}|AIza[0-9A-Za-z_\-]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9\-]{10,}|SG\.[A-Za-z0-9_\-\.]{16,})\b/',
        '***',
        $line
    );
    // 3) = / : の後ろの長いクオート文字列（ハードコード値）
    $line = preg_replace('/([=:]\s*[\'"])[A-Za-z0-9_\-\.\/\+]{16,}([\'"])/', '${1}***${2}', $line);
    return $line;
}

/**
 * `NAME=値` / `NAME: 値` 形式から実際の値を取り出す（取り込みON時のみ使用）。
 * process.env.X / getenv 等の「参照」は値ではないので除外。取れなければ null。
 */
function extract_env_value(string $line, string $env): ?string
{
    if (!preg_match('/' . preg_quote($env, '/') . '["\']?\s*[:=]\s*["\']?([^\s"\'#;]{6,})/', $line, $m)) {
        return null;
    }
    $val = $m[1];
    if (preg_match('#^(process\.env|os\.environ|getenv|env\(|\$_ENV|\$_SERVER|\$\{|import\.meta|<|\{\{|%)#i', $val)) {
        return null;   // コード上の参照（値ではない）
    }
    return $val;
}

/** その env 名が既知プロバイダのいずれかに定義済みか */
function is_known_env(array $providers, string $envVar): bool
{
    foreach ($providers as $p) {
        foreach (($p['env'] ?? []) as $env) {
            if ($env === $envVar) {
                return true;
            }
        }
    }
    return false;
}
