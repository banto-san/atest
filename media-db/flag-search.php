<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$data       = load_data();
$pageTitle  = 'フラグ(媒体)別 逆引き検索';
$currentNav = 'flag-search';
$pageScript = 'page-flag-search.js';

require __DIR__ . '/layout_top.php';
?>
<div class="max-w-6xl mx-auto space-y-6">
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
            <!-- ドメイン重複ランキング（このサイトで集計：準備中） -->
            <div class="border border-purple-200 rounded-xl bg-purple-50 p-6 text-center">
                <p class="text-gray-700 font-medium mb-1">「{{ selectedMediaName }}」の<b>ドメイン重複ランキング</b>（準備中）</p>
                <p class="text-xs text-gray-500">このリスト元の顧客を「他媒体検索」した結果がたまると、よく併用されている媒体ドメインを多い順で表示します。（検索API接続後に有効になります）</p>
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
                                <button @click="searchOtherMedia(client)"
                                   class="inline-flex items-center gap-1 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded px-2 py-1 ml-auto">
                                    <i data-lucide="search" class="w-3 h-3"></i> 他媒体を調べる
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
