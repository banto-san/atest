<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$data       = load_data();
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
                            <span class="text-xs text-gray-500 bg-white px-2 py-1 rounded border">{{ client.ourService }}</span>
                        </div>
                        <div class="text-sm text-gray-600 mb-2">受注日: <span class="font-bold text-gray-800">{{ client.orderDate }}</span> / 業界: {{ client.industry }}</div>

                        <div class="mt-3 pt-3 border-t border-gray-200">
                            <p class="text-xs font-medium text-gray-500 mb-1">他に利用している媒体:</p>
                            <div class="flex flex-wrap gap-1">
                                <span v-for="media in getMediaDetails(client.usedMediaIds)" :key="media.id"
                                      class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-white border border-gray-200 text-gray-700 shadow-sm">
                                    {{ media.name }}
                                </span>
                                <span v-if="client.usedMediaIds.length === 0" class="text-xs text-gray-400 italic">情報なし</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 併用媒体ランキング -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 flex flex-col h-[600px]">
            <div class="flex justify-between items-center mb-4 border-b pb-2">
                <h3 class="text-lg font-bold flex items-center text-gray-800">
                    <i data-lucide="bar-chart-3" class="w-5 h-5 mr-2 text-purple-600"></i>
                    指定期間: 併用媒体ランキング
                </h3>
                <span class="text-xs text-gray-500">対象: {{ filteredClientsByDate.length }}社</span>
            </div>

            <div class="flex-1 overflow-y-auto pr-2">
                <div v-if="periodMediaRanking.length === 0" class="text-center p-8 text-gray-500">
                    集計データがありません。
                </div>
                <ul v-else class="space-y-3">
                    <li v-for="(item, index) in periodMediaRanking" :key="item.media.id"
                        class="flex items-center p-3 rounded-lg border"
                        :class="index < 3 ? 'bg-orange-50 border-orange-100' : 'bg-white border-gray-100'">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold mr-3 shrink-0"
                             :class="index === 0 ? 'bg-yellow-400 text-yellow-900' : index === 1 ? 'bg-gray-300 text-gray-800' : index === 2 ? 'bg-orange-300 text-orange-900' : 'bg-gray-100 text-gray-500'">
                            {{ index + 1 }}
                        </div>
                        <div class="flex-1">
                            <div class="font-bold text-gray-900">{{ item.media.name }}</div>
                            <div class="text-xs text-gray-500">{{ item.media.domain }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-lg font-bold text-blue-600">{{ item.count }}<span class="text-sm font-normal text-gray-500 ml-1">社</span></div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
