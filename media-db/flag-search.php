<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$data       = load_data();
$canSearch  = is_admin();   // API課金が発生する検索は管理者のみ
$pageTitle  = 'フラグ(媒体)別 逆引き検索';
$currentNav = 'flag-search';
$pageScript = 'page-flag-search.js';

require __DIR__ . '/layout_top.php';
?>
<div class="max-w-6xl mx-auto space-y-6">

    <!-- 当月のAPI使用額（OpenAI Adminキー設定時のみ表示） -->
    <div v-if="monthlyCost && monthlyCost.enabled" class="flex justify-end">
        <span class="inline-flex items-center gap-1.5 text-xs bg-white border border-gray-200 rounded-full px-3 py-1.5 shadow-sm">
            <i data-lucide="circle-dollar-sign" class="w-4 h-4 text-green-600"></i>
            <span class="text-gray-500">OpenAI 当月使用分:</span>
            <template v-if="monthlyCost.error"><span class="text-gray-400">{{ monthlyCost.error }}</span></template>
            <template v-else>
                <span class="font-bold text-gray-800">¥{{ Number(monthlyCost.jpy).toLocaleString() }}</span>
                <span class="text-gray-400">（約 ${{ monthlyCost.usd }} / レート{{ monthlyCost.rate }}）</span>
            </template>
        </span>
    </div>

    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
        <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
            <label class="block text-sm font-bold text-gray-700 mb-2">リスト元（媒体）を選んで、その顧客が「他に利用している媒体」を見る</label>
            <div class="flex items-center space-x-4">
                <select v-model="selectedMediaId" class="flex-1 max-w-md border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm font-medium text-gray-800">
                    <option value="">-- リスト元を選択してください --</option>
                    <option v-for="media in sourceMediaList" :key="media.id" :value="media.id">
                        {{ media.name }}
                    </option>
                </select>
                <div v-if="selectedMediaId" class="text-sm font-medium text-gray-600 bg-white px-3 py-1.5 rounded border">
                    該当: <span class="text-blue-600 font-bold text-lg">{{ filteredClientsByFlag.length }}</span> 社
                </div>
            </div>
        </div>

        <div v-if="!selectedMediaId" class="text-center py-12 text-gray-400 border-2 border-dashed border-gray-200 rounded-xl">
            <i data-lucide="filter" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
            <p>上のセレクトボックスからリスト元（受注の獲得元媒体）を選択してください。</p>
        </div>

        <div v-else class="space-y-6">
            <!-- ドメイン重複ランキング -->
            <div class="border border-gray-200 rounded-xl overflow-hidden bg-white shadow-sm">
                <div class="bg-white px-6 py-5 border-b border-gray-200 flex items-center justify-between gap-2">
                    <div>
                        <h3 class="font-bold text-gray-800 text-xl">他に利用している媒体ランキング</h3>
                        <p class="text-sm text-gray-500">「{{ selectedMediaName }}」から受注した顧客が、他に利用している媒体ドメイン</p>
                    </div>
                    <?php if ($canSearch): ?>
                    <button @click="searchAllPending"
                            class="text-xs font-medium text-blue-600 border border-blue-200 rounded px-2 py-1 hover:bg-blue-50 shrink-0">
                        未取得をまとめて検索
                    </button>
                    <?php endif; ?>
                </div>
                <div v-if="flaggedRanking.length === 0" class="text-center p-8 text-gray-400">
                    まだ集計データがありません。<br>
                    <span class="text-xs">下の各顧客で「他媒体を調べる」を実行すると、ここに集計されます。</span>
                </div>
                <table v-else class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-700 bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-center w-24 font-bold">No.</th>
                            <th class="px-6 py-3 font-bold">ドメイン</th>
                            <th class="px-6 py-3 text-center w-32 font-bold">重複件数</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in flaggedRanking" :key="item.domain" class="bg-white border-b hover:bg-blue-50 transition-colors">
                            <td class="px-6 py-3 text-center text-gray-500">{{ index + 1 }}</td>
                            <td class="px-6 py-3">
                                <a :href="'https://' + item.domain" target="_blank" rel="noopener" class="text-blue-600 hover:underline">{{ item.domain }}</a>
                            </td>
                            <td class="px-6 py-3 text-center font-bold text-gray-700">{{ item.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 該当顧客リスト -->
            <div class="border border-gray-200 rounded-xl overflow-hidden mt-6">
                <div class="bg-gray-100 px-4 py-3 border-b border-gray-200 font-bold text-gray-700 flex justify-between">
                    <span>「{{ selectedMediaName }}」から受注した顧客一覧 ({{ filteredClientsByFlag.length }}社)</span>
                </div>
                <div class="max-h-[460px] overflow-y-auto bg-white p-4">
                    <ul class="space-y-3">
                        <li v-for="client in filteredClientsByFlag" :key="client.id" class="p-4 border border-gray-100 rounded-lg shadow-sm">
                            <div class="flex justify-between items-center mb-2">
                                <h4 class="font-bold text-gray-900 text-lg">{{ client.name }}</h4>
                                <span class="text-xs text-gray-500">受注日: {{ client.orderDate }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ client.industry }}</span>
                                <span v-if="client.address" class="text-xs bg-gray-50 text-gray-500 px-2 py-0.5 rounded border">{{ client.address }}</span>
                                <?php if ($canSearch): ?>
                                <button @click="searchOtherMedia(client)" :disabled="searchingId === client.id"
                                   class="inline-flex items-center gap-1 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded px-2 py-1 ml-auto disabled:opacity-50">
                                    <i :data-lucide="searchingId === client.id ? 'loader' : 'search'" :class="['w-3 h-3', searchingId === client.id ? 'sync-spin' : '']"></i>
                                    {{ searchingId === client.id ? '検索中…' : '他媒体を調べる' }}
                                </button>
                                <?php endif; ?>
                            </div>
                            <div v-if="client.foundMedia && client.foundMedia.length" class="mt-2 pl-1">
                                <p class="text-xs font-medium text-gray-500 mb-1">他媒体（{{ client.foundMedia.length }}件）:</p>
                                <ul class="flex flex-wrap gap-1">
                                    <li v-for="(m, i) in client.foundMedia" :key="i">
                                        <a :href="m.url" target="_blank" rel="noopener" class="inline-block text-xs text-blue-700 bg-blue-50 border border-blue-100 rounded px-2 py-0.5 hover:bg-blue-100">{{ m.domain }}</a>
                                    </li>
                                </ul>
                            </div>
                            <div v-else-if="client.searchedAt" class="text-xs text-gray-400 mt-1">
                                他媒体は見つかりませんでした。
                                <span v-if="client.searchNote" class="block mt-0.5">AIの回答: {{ client.searchNote }}</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
