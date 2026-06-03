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
            <label class="block text-sm font-bold text-gray-700 mb-2">フラグ（媒体）を選択して顧客を絞り込む</label>
            <div class="flex items-center space-x-4">
                <select v-model="selectedMediaId" class="flex-1 max-w-md border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm font-medium text-gray-800">
                    <option value="">-- 媒体を選択してください --</option>
                    <option v-for="media in mediaList" :key="media.id" :value="media.id">
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
            <p>上のセレクトボックスから媒体（リスト元）を選択してください。</p>
        </div>

        <div v-else class="space-y-6">
            <!-- 重複ランキング表 -->
            <div class="border border-gray-200 rounded-xl overflow-hidden bg-white shadow-sm">
                <div class="bg-white px-6 py-8 border-b border-gray-200 text-center">
                    <h3 class="font-bold text-gray-800 text-2xl mb-4">重複ランキング</h3>
                    <p class="text-red-600 font-bold text-lg">リスト元が「{{ selectedMediaName }}」で重複ランキングを出力</p>
                </div>
                <div class="p-0">
                    <div v-if="flaggedMediaRanking.length === 0" class="text-center p-8 text-gray-500">
                        併用データがありません。
                    </div>
                    <table v-else class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-700 bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th scope="col" class="px-6 py-4 text-center w-24 font-bold">No.</th>
                                <th scope="col" class="px-6 py-4 text-center font-bold">ドメイン / 媒体名</th>
                                <th scope="col" class="px-6 py-4 text-center w-32 font-bold">重複件数</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, index) in flaggedMediaRanking" :key="item.media.id" class="bg-white border-b hover:bg-blue-50 transition-colors">
                                <td class="px-6 py-4 text-center text-gray-500">{{ index + 1 }}</td>
                                <td class="px-6 py-4 text-center font-medium text-blue-600 hover:underline cursor-pointer">
                                    {{ item.media.name }}
                                    <span v-if="item.media.domain && item.media.domain !== '-' && item.media.domain !== 'unknown'" class="text-xs text-gray-400 ml-1">({{ item.media.domain }})</span>
                                </td>
                                <td class="px-6 py-4 text-center font-bold text-gray-700">{{ item.count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 該当顧客リスト -->
            <div class="border border-gray-200 rounded-xl overflow-hidden mt-8">
                <div class="bg-gray-100 px-4 py-3 border-b border-gray-200 font-bold text-gray-700 flex justify-between">
                    <span>ランキング対象の顧客一覧 ({{ filteredClientsByFlag.length }}社)</span>
                </div>
                <div class="max-h-[400px] overflow-y-auto bg-white p-4">
                    <ul class="space-y-3">
                        <li v-for="client in filteredClientsByFlag" :key="client.id" class="p-4 border border-gray-100 rounded-lg shadow-sm">
                            <div class="flex justify-between items-center mb-2">
                                <h4 class="font-bold text-gray-900 text-lg">{{ client.name }}</h4>
                                <span class="text-xs text-gray-500">受注日: {{ client.orderDate }}</span>
                            </div>
                            <div class="flex gap-2 mb-3">
                                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ client.industry }}</span>
                                <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded border border-blue-100">{{ client.ourService }}</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
