<?php
/**
 * 共通レイアウト（下部）
 * --------------------------------------------------------------------------
 * メイン領域を閉じ、フロントで使う初期データ・CSRFトークンを埋め込み、
 * 共通JS(app-core.js) → ページ専用JS($pageScript) の順で読み込む。
 */
?>
            </div><!-- /ページ本文 -->
        </main>
    </div>

    <script>
        // PHPから渡す初期データと CSRF トークン
        window.__APP_DATA__ = <?= json_encode(
            [
                'users'   => $data['users']   ?? [],
                'media'   => $data['media']   ?? [],
                'clients' => $data['clients'] ?? [],
            ],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?>;
        window.__CSRF__ = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__IS_ADMIN__ = <?= is_admin() ? 'true' : 'false' ?>;
    </script>
    <!-- 共通フロント処理（データストア / 保存 / CSV / ランキング集計） -->
    <script src="app-core.js"></script>
    <?php if (!empty($pageScript)): ?>
    <!-- このページ専用の Vue アプリ -->
    <script src="<?= h($pageScript) ?>"></script>
    <?php endif; ?>
</body>
</html>
