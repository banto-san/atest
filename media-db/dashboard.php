<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$data       = load_data();
$canSearch  = is_admin();   // API課金が発生する検索は管理者のみ
$pageTitle  = '受注一覧・ランキング';
$currentNav = 'dashboard';
$pageScript = 'page-dashboard.js';

require __DIR__ . '/layout_top.php';
?>
<div class="max-w-6xl mx-auto space-y-6">

    <!-- 集計期間の指定 -->
    <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center space-x-3">
            <div class="bg-blue-100 text-blue-600 p-2 rounded-lg">
                <i data-lucide="calendar-search" class="w-5 h-5"></i>
            </div>
            <span class="font-bold text-gray-700">集計期間の指定:</span>
            <div class="flex items-center space-x-2">
                <input type="date" v-model="filterStartDate" class="border border-gray-300 rounded-md p-1.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                <span class="text-gray-500">〜</span>
                <input type="date" v-model="filterEndDate" class="border border-gray-300 rounded-md p-1.5 text-sm focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>
        <div>
            <button @click="resetDateFilter" class="text-sm font-medium text-gray-500 hover:text-gray-800 hover:underline px-3 py-1">全期間にリセット</button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 受注リスト -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 flex flex-col h-[600px]">
            <div class="flex justify-between items-center mb-4 border-b pb-2">
                <h3 class="text-lg font-bold flex items-center text-gray-800">
                    <i data-lucide="list" class="w-5 h-5 mr-2 text-blue-600"></i>
                    受注顧客リスト <span v-if="dateRangeText" class="text-sm font-normal text-gray-500 ml-2 bg-gray-100 px-2 py-0.5 rounded">{{ dateRangeText }}</span>
                </h3>
                <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2.5 py-0.5 rounded-full">{{ filteredClientsByDate.length }}社</span>
            </div>

            <div class="flex-1 overflow-y-auto pr-2">
                <div v-if="filteredClientsByDate.length === 0" class="text-center p-8 text-gray-500">
                    指定期間の受注データはありません。
                </div>
                <div v-else class="space-y-4">
                    <div v-for="client in filteredClientsByDate" :key="client.id" class="border border-gray-100 rounded-lg p-4 bg-gray-50 hover:bg-blue-50 transition relative group">
                        <button @click="deleteClient(client.id)" class="absolute top-2 right-2 p-1.5 bg-white text-red-500 rounded border border-red-100 hover:bg-red-50 opacity-0 group-hover:opacity-100 transition-opacity" title="この顧客を削除">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                        <div class="flex justify-between items-start mb-2 pr-8">
                            <h4 class="font-bold text-gray-900">{{ client.name }}</h4>
                            <span class="text-xs text-gray-500">{{ client.industry }}</span>
                        </div>
                        <div class="text-sm text-gray-600 mb-1">受注日: <span class="font-bold text-gray-800">{{ client.orderDate }}</span></div>
                        <div v-if="client.address" class="text-sm text-gray-600 mb-1">住所: <span class="text-gray-800">{{ client.address }}</span></div>
                        <div class="text-sm text-gray-600 mb-2 flex items-center gap-1">
                            <span>受注リスト元:</span>
                            <span v-if="sourceMedia(client)" class="inline-flex items-center text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100 px-2 py-0.5 rounded">
                                {{ sourceMedia(client).name }}
                            </span>
                            <span v-else class="text-xs text-gray-400 italic">未設定</span>
                        </div>

                        <div class="mt-3 pt-3 border-t border-gray-200">
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ($canSearch): ?>
                                <button @click="searchOtherMedia(client)" :disabled="searchingId === client.id"
                                        class="inline-flex items-center gap-1.5 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md px-3 py-1.5 shadow-sm disabled:opacity-50">
                                    <i :data-lucide="searchingId === client.id ? 'loader' : 'search'" :class="['w-4 h-4', searchingId === client.id ? 'sync-spin' : '']"></i>
                                    {{ searchingId === client.id ? '検索中…' : '他媒体を調べる' }}
                                </button>
                                <?php endif; ?>
                                <span v-if="client.searchedAt" class="text-[11px] text-gray-400">最終検索: {{ client.searchedAt }}</span>
                            </div>
                            <div v-if="client.foundMedia && client.foundMedia.length" class="mt-2">
                                <p class="text-xs font-medium text-gray-500 mb-1">他に利用している媒体（{{ client.foundMedia.length }}件）:</p>
                                <ul class="flex flex-col gap-1 max-h-40 overflow-y-auto">
                                    <li v-for="(m, i) in client.foundMedia" :key="i" class="text-xs flex items-center gap-1.5">
                                        <span class="text-gray-400 shrink-0">{{ i + 1 }}.</span>
                                        <a :href="m.url" target="_blank" rel="noopener" class="text-blue-600 hover:underline truncate">{{ m.title || m.url }}</a>
                                        <span class="text-gray-400 shrink-0">（{{ m.domain }}）</span>
                                    </li>
                                </ul>
                            </div>
                            <p v-else-if="client.searchedAt" class="text-xs text-gray-400 mt-1">他媒体は見つかりませんでした。</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 併用媒体（ドメイン）重複ランキング -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 flex flex-col h-[600px]">
            <div class="flex justify-between items-center mb-4 border-b pb-2 gap-2">
                <h3 class="text-lg font-bold flex items-center text-gray-800">
                    <i data-lucide="bar-chart-3" class="w-5 h-5 mr-2 text-purple-600"></i>
                    併用媒体ランキング
                </h3>
                <?php if ($canSearch): ?>
                <button @click="searchAllPending"
                        class="text-xs font-medium text-blue-600 border border-blue-200 rounded px-2 py-1 hover:bg-blue-50 shrink-0">
                    未取得をまとめて検索
                </button>
                <?php endif; ?>
            </div>

            <div class="flex-1 overflow-y-auto pr-2">
                <div v-if="foundRanking.length === 0" class="text-center p-8 text-gray-400">
                    まだ集計データがありません。<br>
                    <span class="text-xs">各受注客の「他媒体を調べる」を実行すると、ここに<br>ドメインの重複ランキング（何社が同じ媒体を使っているか）が出ます。</span>
                </div>
                <table v-else class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-700 bg-gray-50 border-b border-gray-200 sticky top-0">
                        <tr>
                            <th class="px-3 py-2 text-center w-12 font-bold">No.</th>
                            <th class="px-3 py-2 font-bold">ドメイン</th>
                            <th class="px-3 py-2 text-center w-20 font-bold">重複</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in foundRanking" :key="item.domain" class="bg-white border-b hover:bg-blue-50 transition-colors">
                            <td class="px-3 py-2 text-center text-gray-500">{{ index + 1 }}</td>
                            <td class="px-3 py-2">
                                <a :href="'https://' + item.domain" target="_blank" rel="noopener" class="text-blue-600 hover:underline">{{ item.domain }}</a>
                            </td>
                            <td class="px-3 py-2 text-center font-bold text-gray-700">{{ item.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
