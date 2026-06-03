<?php
/**
 * 共通レイアウト（上部）
 * --------------------------------------------------------------------------
 * ログイン後の全ページで共通の「サイドメニュー + 上部ヘッダー」を描画する。
 * 呼び出し前に以下を設定しておくこと:
 *   $pageTitle  … ヘッダーに出すページ名
 *   $currentNav … 現在のナビキー（nav_items() のキー）
 *   $data       … load_data() の結果（サイドバーの件数表示に使用）
 *   $pageScript … （任意）このページ専用の Vue アプリ JS のパス
 *
 * Vue は #app（メイン領域）にマウントされる。サイドメニューはサーバ側で描画する。
 */
if (!isset($pageTitle))  { $pageTitle = MDB_APP_NAME; }
if (!isset($currentNav)) { $currentNav = ''; }
if (!isset($data))       { $data = load_data(); }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> | <?= h(MDB_APP_NAME) ?></title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Vue.js -->
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        [v-cloak] { display: none; }
        .sync-spin { animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 font-sans h-screen flex overflow-hidden">

    <div class="w-full flex h-full">

        <!-- サイドメニュー（サーバ側で描画） -->
        <aside class="w-64 bg-gray-900 text-white flex flex-col shrink-0">
            <div class="p-6 border-b border-gray-800 flex items-center space-x-3">
                <div class="bg-blue-500 text-white p-2 rounded-lg">
                    <i data-lucide="database" class="w-5 h-5"></i>
                </div>
                <h1 class="text-xl font-bold tracking-tight"><?= h(MDB_APP_NAME) ?></h1>
            </div>

            <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
                <?php foreach (nav_items() as $key => [$label, $icon, $href]): ?>
                    <a href="<?= h($href) ?>"
                       class="flex items-center space-x-3 px-4 py-3 rounded-lg transition-colors <?= $currentNav === $key ? 'bg-blue-600 text-white font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-white' ?>">
                        <i data-lucide="<?= h($icon) ?>" class="w-5 h-5"></i>
                        <span><?= h($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="p-4 border-t border-gray-800 text-sm text-gray-500 flex justify-between items-center">
                <div>
                    <p>登録顧客数: <?= count($data['clients']) ?>社</p>
                    <p>登録媒体数: <?= count($data['media']) ?>媒体</p>
                </div>
                <a href="index.php?action=logout" class="text-gray-400 hover:text-white p-1 rounded hover:bg-gray-800" title="ログアウト">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                </a>
            </div>
        </aside>

        <!-- メインコンテンツ（Vue が #app にマウント） -->
        <main id="app" v-cloak class="flex-1 flex flex-col h-full overflow-hidden bg-gray-50">

            <!-- 上部ヘッダー -->
            <header class="bg-white border-b border-gray-200 p-4 flex items-center justify-between shrink-0 shadow-sm z-10">
                <div class="flex items-center">
                    <h2 class="text-xl font-bold text-gray-800 mr-4"><?= h($pageTitle) ?></h2>
                    <!-- データ保存状態インジケーター -->
                    <div v-if="store.isSyncing" class="flex items-center text-xs font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded">
                        <i data-lucide="loader" class="w-3 h-3 mr-1 sync-spin"></i> サーバーへ保存中...
                    </div>
                    <div v-else-if="store.syncError" class="flex items-center text-xs font-bold text-red-600 bg-red-50 px-2 py-1 rounded">
                        <i data-lucide="alert-circle" class="w-3 h-3 mr-1"></i> 保存エラー
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <button @click="exportCsv" class="flex items-center space-x-2 bg-green-50 text-green-700 px-4 py-2 rounded-lg border border-green-200 hover:bg-green-100 transition shadow-sm font-medium">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        <span>現在のリストをCSV出力</span>
                    </button>
                </div>
            </header>

            <!-- ページ本文 -->
            <div class="flex-1 overflow-y-auto p-6 md:p-8">
