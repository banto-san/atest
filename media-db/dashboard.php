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

    <!-- 当月のAPI使用額（OpenAI Adminキー設定時のみ表示） -->
    <div v-if="monthlyCost && monthlyCost.enabled" class="flex flex-col items-end gap-1">
        <span class="inline-flex items-center gap-1.5 text-xs bg-white border border-gray-200 rounded-full px-3 py-1.5 shadow-sm">
            <i data-lucide="circle-dollar-sign" class="w-4 h-4 text-green-600"></i>
            <span class="text-gray-500">OpenAI 当月使用分<span v-if="monthlyCost.scope">（{{ monthlyCost.scope === 'project' ? 'このサイト分' : '組織全体' }}）</span>:</span>
            <template v-if="monthlyCost.error"><span class="text-gray-400">{{ monthlyCost.error }}</span></template>
            <template v-else>
                <span class="font-bold text-gray-800">¥{{ Number(monthlyCost.jpy).toLocaleString() }}</span>
                <span class="text-gray-400">（約 ${{ monthlyCost.usd }} / レート{{ monthlyCost.rate }}）</span>
            </template>
        </span>
        <span v-if="monthlyCost.note" class="text-[11px] text-amber-600 max-w-md text-right leading-snug">{{ monthlyCost.note }}</span>
    </div>

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
                            <h4 @click="openDetail(client)" class="font-bold text-gray-900 cursor-pointer hover:text-blue-700 hover:underline" title="詳細を見る">{{ client.name }}</h4>
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
                                <button @click="openDetail(client)"
                                        class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-md px-3 py-1.5 shadow-sm hover:bg-gray-50">
                                    <i data-lucide="eye" class="w-4 h-4"></i> 詳細
                                </button>
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
                            <div v-else-if="client.searchedAt" class="text-xs text-gray-400 mt-1">
                                他媒体は見つかりませんでした。
                                <span v-if="client.searchNote" class="block text-gray-400 mt-0.5">AIの回答: {{ client.searchNote }}</span>
                            </div>
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

<!-- 顧客詳細モーダル -->
<div v-if="selectedClient" @click.self="closeDetail"
     class="fixed inset-0 z-50 bg-gray-900/60 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[88vh] overflow-y-auto">
        <!-- ヘッダー -->
        <div class="flex items-start justify-between gap-3 p-6 border-b border-gray-200 sticky top-0 bg-white rounded-t-2xl">
            <div>
                <h3 class="text-xl font-bold text-gray-900">{{ selectedClient.name }}</h3>
                <p class="text-sm text-gray-500 mt-0.5">{{ selectedClient.industry }}</p>
            </div>
            <button @click="closeDetail" class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 p-1.5 rounded-lg shrink-0" title="閉じる">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <!-- 本文 -->
        <div class="p-6 space-y-5">
            <!-- 基本情報 -->
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <div class="flex gap-2"><dt class="text-gray-500 shrink-0 w-20">受注日</dt><dd class="font-medium text-gray-800">{{ selectedClient.orderDate }}</dd></div>
                <div class="flex gap-2"><dt class="text-gray-500 shrink-0 w-20">最終検索</dt><dd class="text-gray-800">{{ selectedClient.searchedAt || '未検索' }}</dd></div>
                <div class="flex gap-2 sm:col-span-2"><dt class="text-gray-500 shrink-0 w-20">住所</dt><dd class="text-gray-800">{{ selectedClient.address || '—' }}</dd></div>
                <div class="flex items-center gap-2 sm:col-span-2"><dt class="text-gray-500 shrink-0 w-20">受注リスト元</dt>
                    <dd>
                        <span v-if="sourceMedia(selectedClient)" class="inline-flex items-center text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100 px-2 py-0.5 rounded">{{ sourceMedia(selectedClient).name }}</span>
                        <span v-else class="text-gray-400">未設定</span>
                    </dd>
                </div>
            </dl>

            <!-- 他媒体 -->
            <div class="border-t border-gray-200 pt-4">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h4 class="font-bold text-gray-800">他に利用している媒体 <span class="text-blue-600">{{ detailMedia.length }}</span> 件</h4>
                    <?php if ($canSearch): ?>
                    <button @click="searchOtherMedia(selectedClient)" :disabled="searchingId === selectedClient.id"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md px-3 py-1.5 shadow-sm disabled:opacity-50">
                        <i :data-lucide="searchingId === selectedClient.id ? 'loader' : 'refresh-cw'" :class="['w-3.5 h-3.5', searchingId === selectedClient.id ? 'sync-spin' : '']"></i>
                        {{ searchingId === selectedClient.id ? '検索中…' : '再検索' }}
                    </button>
                    <?php endif; ?>
                </div>
                <ul v-if="detailMedia.length" class="space-y-2">
                    <li v-for="(m, i) in detailMedia" :key="i" class="flex items-start gap-2 text-sm border border-gray-100 rounded-lg p-2.5 hover:bg-gray-50">
                        <span class="text-gray-400 shrink-0 w-5 text-right">{{ i + 1 }}</span>
                        <div class="min-w-0">
                            <a :href="m.url" target="_blank" rel="noopener" class="text-blue-600 hover:underline break-words">{{ m.title || m.url }}</a>
                            <span class="block text-xs text-gray-400 break-all">{{ m.domain }}</span>
                        </div>
                    </li>
                </ul>
                <div v-else class="text-sm text-gray-500 bg-gray-50 border border-gray-100 rounded-lg p-4 text-center">
                    まだ媒体が見つかっていません。
                    <span v-if="selectedClient.searchNote" class="block mt-1 text-gray-400 text-xs">AIの回答: {{ selectedClient.searchNote }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
