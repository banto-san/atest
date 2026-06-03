<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$data       = load_data();
$pageTitle  = 'データ登録・インポート';
$currentNav = 'register';
$pageScript = 'page-register.js';

require __DIR__ . '/layout_top.php';
?>
<div class="max-w-5xl mx-auto space-y-6">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- 手動登録フォーム -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <h3 class="text-lg font-bold mb-4 flex items-center border-b pb-2">
                <i data-lucide="user-plus" class="w-5 h-5 mr-2 text-blue-600"></i>
                顧客を1件ずつ登録
            </h3>
            <form @submit.prevent="registerSingleClient" class="space-y-4">
                <div class="grid grid-cols-2 gap-3 mb-2">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">顧客名 <span class="text-red-500">*</span></label>
                        <input type="text" v-model="newClient.name" required placeholder="株式会社〇〇" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">業界</label>
                        <input type="text" v-model="newClient.industry" placeholder="IT・通信" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">自社受注サービス</label>
                        <input type="text" v-model="newClient.ourService" placeholder="SEO対策" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">受注日</label>
                        <input type="date" v-model="newClient.orderDate" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">利用している他媒体 (複数選択可)</label>
                    <div class="h-48 overflow-y-auto border border-gray-200 rounded-md p-2 bg-gray-50 mb-2">
                        <div v-for="(media, index) in mediaList" :key="media.id" class="flex items-center justify-between mb-1 p-1.5 hover:bg-white hover:shadow-sm rounded group transition-all">
                            <div class="flex items-center">
                                <input type="checkbox" :id="'cb-'+media.id" :value="media.id" v-model="newClient.usedMediaIds" class="w-4 h-4 text-blue-600 rounded border-gray-300">
                                <label :for="'cb-'+media.id" class="ml-2 text-sm text-gray-700 cursor-pointer">{{ media.name }}</label>
                            </div>
                            <div class="hidden group-hover:flex items-center space-x-2 bg-white rounded px-1 shadow-sm border border-gray-100">
                                <button type="button" @click.prevent="deleteMedia(index)" class="p-1 text-gray-400 hover:text-red-600" title="削除">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1">【追加】リストにないフラグ(媒体)を手動入力</label>
                        <input type="text" v-model="newClientManualFlags" placeholder="例: WELBOX, 独自の広告枠 (複数ある場合はカンマ区切り)" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">※ここに入力したフラグは自動的にシステムに新規登録されます。</p>
                    </div>
                </div>
                <button type="submit" :disabled="store.isSyncing" class="w-full bg-blue-600 text-white font-medium py-2 px-4 rounded-md hover:bg-blue-700 transition shadow-sm mt-4 disabled:opacity-50">
                    登録する
                </button>
                <p v-if="successMessage" class="text-green-600 text-sm mt-2 text-center font-bold">{{ successMessage }}</p>
            </form>
        </div>

        <!-- 一括インポート -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <h3 class="text-lg font-bold mb-4 flex items-center border-b pb-2">
                <i data-lucide="table" class="w-5 h-5 mr-2 text-green-600"></i>
                Excel/CSVから一括インポート
            </h3>
            <p class="text-sm text-gray-600 mb-4">
                Excelの表をコピーして下の枠に貼り付けるか、CSVのテキストを貼り付けてください。
                <br><span class="font-bold text-red-500 text-xs">フォーマット: 顧客名, 業界, 自社サービス, 受注日(YYYY-MM-DD), 利用媒体(カンマ区切り)</span>
            </p>

            <textarea v-model="importText" rows="10" placeholder="株式会社A&#9;IT&#9;SEO&#9;2026-06-02&#9;イツザイ,Sansan&#10;株式会社B&#9;建設&#9;HP制作&#9;2026-06-01&#9;リクナビ"
                      class="w-full border border-gray-300 rounded-md p-3 text-sm font-mono focus:ring-blue-500 focus:border-blue-500 mb-4 bg-gray-50 whitespace-pre"></textarea>

            <button @click="processImport" :disabled="store.isSyncing" class="w-full bg-green-600 text-white font-medium py-2 px-4 rounded-md hover:bg-green-700 transition shadow-sm flex justify-center items-center disabled:opacity-50">
                <i data-lucide="file-input" class="w-4 h-4 mr-2"></i> データを読み込む
            </button>
            <p v-if="importMessage" :class="importError ? 'text-red-600' : 'text-green-600'" class="text-sm mt-3 font-medium bg-gray-50 p-2 rounded border">{{ importMessage }}</p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
