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
<!-- Excel(.xlsx)読み込み用ライブラリ（CSVはこれが無くても動きます） -->
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

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
                        <label class="block text-sm font-medium text-gray-700 mb-1">受注日</label>
                        <input type="date" v-model="newClient.orderDate" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">住所</label>
                        <input type="text" v-model="newClient.address" placeholder="東京都渋谷区神南1-2-3" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">※ダッシュボードの「会社名＋住所」検索に使います（番地は自動で除いて検索）。</p>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">受注リスト元（獲得元の媒体）</label>
                        <select v-model="newClient.sourceMediaId" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- 選択しない --</option>
                            <option v-for="media in mediaList" :key="media.id" :value="media.id">{{ media.name }}</option>
                        </select>
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
                        <input type="text" v-model="newClientManualFlags" title="リストカテゴリー最終を入力してください" placeholder="例: WELBOX, 独自の広告枠 (複数ある場合はカンマ区切り)" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">※ここに入力したフラグは自動的にシステムに新規登録されます。</p>
                    </div>
                </div>
                <button type="submit" :disabled="store.isSyncing" class="w-full bg-blue-600 text-white font-medium py-2 px-4 rounded-md hover:bg-blue-700 transition shadow-sm mt-4 disabled:opacity-50">
                    登録する
                </button>
                <p v-if="successMessage" class="text-green-600 text-sm mt-2 text-center font-bold">{{ successMessage }}</p>
            </form>
        </div>

        <!-- 一括インポート（ファイル選択） -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <h3 class="text-lg font-bold mb-4 flex items-center border-b pb-2">
                <i data-lucide="table" class="w-5 h-5 mr-2 text-green-600"></i>
                Excel/CSVファイルから一括インポート
            </h3>
            <p class="text-sm text-gray-600 mb-3">
                Excel(.xlsx) または CSV ファイルを選んで取り込みます。
                <br><span class="font-bold text-red-500 text-xs">列の順番（FileMaker書き出しのまま）: ①顧客名 ②住所 ③リスト元媒体 ④業種 ⑤受注日</span>
                <br><span class="text-xs text-gray-500">※見出し行は無くてOK。<b>受注日はファイルの日付ではなく「前営業日」</b>（土日・祝日・連休は自動でさかのぼり）で登録します。「他に利用している媒体」はこの取り込みには含めません。</span>
            </p>

            <!-- ファイル選択エリア -->
            <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100 transition mb-3">
                <i data-lucide="upload-cloud" class="w-8 h-8 text-gray-400 mb-2"></i>
                <span class="text-sm text-gray-600 font-medium">クリックして Excel / CSV ファイルを選択</span>
                <span class="text-xs text-gray-400 mt-1">.xlsx / .csv / .txt に対応</span>
                <input type="file" accept=".csv,.tsv,.txt,.xlsx,.xls" @change="onFileChange" class="hidden">
            </label>

            <div v-if="importFileName" class="text-sm text-gray-700 mb-3 flex items-center gap-2 bg-gray-50 border border-gray-200 rounded p-2">
                <i data-lucide="file" class="w-4 h-4 text-gray-400 shrink-0"></i>
                <span class="truncate">{{ importFileName }}</span>
                <span v-if="importRows.length" class="text-xs text-gray-500 shrink-0">（{{ importRows.length }} 行）</span>
            </div>

            <button @click="processImport" :disabled="store.isSyncing || importRows.length === 0" class="w-full bg-green-600 text-white font-medium py-2 px-4 rounded-md hover:bg-green-700 transition shadow-sm flex justify-center items-center disabled:opacity-50 disabled:cursor-not-allowed">
                <i data-lucide="file-input" class="w-4 h-4 mr-2"></i> このファイルを取り込む
            </button>
            <p v-if="importMessage" :class="importError ? 'text-red-600' : 'text-green-600'" class="text-sm mt-3 font-medium bg-gray-50 p-2 rounded border">{{ importMessage }}</p>
        </div>
    </div>

    <!-- 登録済み顧客一覧（削除・一括削除） -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4 border-b pb-3">
            <h3 class="text-lg font-bold flex items-center text-gray-800">
                <i data-lucide="list" class="w-5 h-5 mr-2 text-blue-600"></i>
                登録済み顧客一覧
                <span class="ml-2 text-sm font-bold bg-blue-100 text-blue-800 px-2.5 py-0.5 rounded-full">{{ store.clients.length }} 社</span>
            </h3>
            <div class="flex items-center gap-2">
                <button @click="deleteSelected" :disabled="selectedClientIds.length === 0"
                        class="inline-flex items-center gap-1 text-sm font-medium text-red-600 border border-red-200 rounded-md px-3 py-1.5 hover:bg-red-50 disabled:opacity-40 disabled:cursor-not-allowed">
                    <i data-lucide="trash-2" class="w-4 h-4"></i> 選択した{{ selectedClientIds.length }}件を削除
                </button>
                <button @click="deleteAllClients" :disabled="store.clients.length === 0"
                        class="inline-flex items-center gap-1 text-sm font-medium text-white bg-red-600 rounded-md px-3 py-1.5 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    <i data-lucide="trash" class="w-4 h-4"></i> 全件削除
                </button>
            </div>
        </div>

        <div v-if="store.clients.length === 0" class="text-center p-8 text-gray-400">
            まだ顧客が登録されていません。
        </div>
        <div v-else class="border border-gray-200 rounded-lg overflow-x-auto max-h-[480px] overflow-y-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-700 bg-gray-100 border-b border-gray-200 sticky top-0">
                    <tr>
                        <th class="px-3 py-3 w-10 text-center">
                            <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" class="w-4 h-4 rounded border-gray-300" title="全選択">
                        </th>
                        <th class="px-3 py-3 font-bold">顧客名</th>
                        <th class="px-3 py-3 font-bold">業界</th>
                        <th class="px-3 py-3 font-bold">住所</th>
                        <th class="px-3 py-3 font-bold">受注日</th>
                        <th class="px-3 py-3 font-bold">リスト元</th>
                        <th class="px-3 py-3 font-bold text-center w-16">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="client in store.clients" :key="client.id" class="bg-white border-b hover:bg-blue-50 transition-colors">
                        <td class="px-3 py-2 text-center">
                            <input type="checkbox" :value="client.id" v-model="selectedClientIds" class="w-4 h-4 rounded border-gray-300">
                        </td>
                        <td class="px-3 py-2 font-medium text-gray-900">{{ client.name }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ client.industry }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ client.address }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ client.orderDate }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ sourceMediaName(client) || '—' }}</td>
                        <td class="px-3 py-2 text-center">
                            <button @click="deleteOneClient(client.id)" class="text-red-600 hover:bg-red-50 p-1.5 rounded" title="この顧客を削除">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
