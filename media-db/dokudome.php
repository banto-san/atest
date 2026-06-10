<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$data       = load_data();
$canEdit    = is_admin();   // 検索(有料API)・除外リスト編集は管理者のみ
$pageTitle  = '独ドメげっと';
$currentNav = 'dokudome';
$pageScript = 'page-dokudome.js';

require __DIR__ . '/layout_top.php';
?>
<!-- Excel(.xlsx)読み込み用ライブラリ（CSVはこれが無くても動きます） -->
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<div class="max-w-6xl mx-auto space-y-6">

    <!-- 説明 -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-start gap-4">
            <div class="bg-emerald-50 text-emerald-600 p-3 rounded-xl shrink-0"><i data-lucide="globe" class="w-7 h-7"></i></div>
            <div>
                <h2 class="text-xl font-bold text-gray-800">独ドメげっと</h2>
                <p class="text-sm text-gray-500 mt-1">会社の<strong class="text-gray-700">本当の公式サイト（独自ドメイン）だけ</strong>を特定します。Excel/CSVで会社一覧を取り込んで<strong class="text-gray-700">まとめて調査</strong>でき、結果はCSVで出力できます。SNS・予約・ポータル・求人・除外ドメインは自動で除外。見つからなければ空白です。</p>
            </div>
        </div>
    </div>

    <!-- ① 一括取り込み（Excel/CSV） -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2"><i data-lucide="upload" class="w-5 h-5 text-emerald-600"></i>① Excel / CSV でまとめて取り込み</h3>
        <p class="text-xs text-gray-500 mb-3">列の順番は <strong>業種 / 顧客名 / 住所 / 電話番号</strong>（先頭の見出し行があってもOK）。.xlsx / .csv / .txt 対応。</p>

        <label class="inline-flex items-center gap-2 cursor-pointer bg-gray-50 hover:bg-gray-100 border border-gray-300 rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700">
            <i data-lucide="file-up" class="w-4 h-4"></i> ファイルを選択
            <input type="file" accept=".csv,.tsv,.txt,.xlsx,.xls" @change="onFileChange" class="hidden">
        </label>
        <span v-if="importFileName" class="ml-2 text-sm text-gray-500">{{ importFileName }}</span>
        <button v-if="companies.length && !bulkRunning" @click="clearImport"
                class="ml-2 inline-flex items-center gap-1 text-sm text-gray-500 border border-gray-200 rounded-md px-2.5 py-1 hover:bg-gray-50 hover:text-red-600">
            <i data-lucide="x" class="w-3.5 h-3.5"></i> 取り消す
        </button>
        <p v-if="importMessage" :class="['text-sm mt-2', importError ? 'text-red-600' : 'text-gray-600']">{{ importMessage }}</p>

        <?php if ($canEdit): ?>
        <div v-if="companies.length" class="mt-4">
            <!-- 進捗バー -->
            <div v-if="bulkRunning || bulkDone > 0" class="mb-3">
                <div class="flex items-center justify-between text-sm mb-1">
                    <span class="font-bold text-gray-700">進捗：{{ bulkDone }} / {{ bulkTotal }} 社</span>
                    <span class="text-gray-400">残り {{ remaining }} 社（{{ progressPct }}%）</span>
                </div>
                <div class="w-full h-3 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-3 bg-emerald-500 transition-all" :style="{ width: progressPct + '%' }"></div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <!-- 実行中：独立した大きな中止ボタン -->
                <template v-if="bulkRunning">
                    <span class="inline-flex items-center gap-2 text-emerald-700 font-bold">
                        <i data-lucide="loader" class="w-5 h-5 sync-spin"></i> 調査中…
                    </span>
                    <button @click="cancelBulk"
                            class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white font-bold text-lg px-8 py-3.5 rounded-xl shadow-md">
                        <i data-lucide="square" class="w-6 h-6"></i> 中止する
                    </button>
                </template>
                <!-- 未開始 / 再開 / 完了 -->
                <template v-else>
                    <button v-if="bulkDone === 0" @click="runBulk"
                            class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-lg px-8 py-3.5 rounded-xl shadow-md">
                        <i data-lucide="search" class="w-6 h-6"></i> {{ bulkTotal }}件をまとめて調べる
                    </button>
                    <button v-else-if="remaining > 0" @click="runBulk"
                            class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-lg px-8 py-3.5 rounded-xl shadow-md">
                        <i data-lucide="play" class="w-6 h-6"></i> 残り {{ remaining }} 件を再開
                    </button>
                    <span v-else class="inline-flex items-center gap-2 text-emerald-700 font-bold text-lg">
                        <i data-lucide="check-circle" class="w-6 h-6"></i> 完了（{{ bulkTotal }}件）
                    </span>
                </template>
            </div>
            <p class="text-xs text-gray-400 mt-2">※ 有料APIを使用します（管理者のみ）。中止しても、あとで「再開」で続きから調べられます。</p>
        </div>
        <?php else: ?>
        <p v-if="companies.length" class="mt-4 text-sm text-gray-500 bg-gray-50 border border-gray-200 rounded px-3 py-2">検索は管理者のみ実行できます（閲覧は可能）。</p>
        <?php endif; ?>
    </div>

    <!-- ② 1件ずつ調べる -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2"><i data-lucide="search" class="w-5 h-5 text-emerald-600"></i>② 1件だけ調べる</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <input v-model="form.name" type="text" placeholder="顧客名（会社名）" @keyup.enter="runOne" class="border border-gray-300 rounded-md p-2.5 focus:ring-emerald-500 focus:border-emerald-500">
            <input v-model="form.industry" type="text" placeholder="業種（任意）" @keyup.enter="runOne" class="border border-gray-300 rounded-md p-2.5 focus:ring-emerald-500 focus:border-emerald-500">
            <input v-model="form.address" type="text" placeholder="住所（任意）" @keyup.enter="runOne" class="border border-gray-300 rounded-md p-2.5 focus:ring-emerald-500 focus:border-emerald-500">
            <input v-model="form.phone" type="text" placeholder="電話番号（任意）" @keyup.enter="runOne" class="border border-gray-300 rounded-md p-2.5 focus:ring-emerald-500 focus:border-emerald-500">
        </div>
        <div class="mt-3">
            <?php if ($canEdit): ?>
            <button @click="runOne" :disabled="searching"
                    class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-5 py-2.5 rounded-lg shadow-sm disabled:opacity-50">
                <i :data-lucide="searching ? 'loader' : 'search'" :class="['w-5 h-5', searching ? 'sync-spin' : '']"></i>
                {{ searching ? '調べています…' : '独自ドメインを調べる' }}
            </button>
            <?php else: ?>
            <span class="text-sm text-gray-500 bg-gray-50 border border-gray-200 rounded px-3 py-2">検索は管理者のみ実行できます（閲覧は可能）。</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- 結果（大きく見やすいカード表示） -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-2 flex-wrap">
            <h3 class="text-lg font-bold text-gray-800">検索結果 <span class="text-gray-400 text-sm font-normal">{{ results.length }}件</span></h3>
            <div class="flex items-center gap-2">
                <button v-if="results.length" @click="exportResults" class="inline-flex items-center gap-1.5 text-sm font-medium text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-1.5 hover:bg-green-100">
                    <i data-lucide="download" class="w-4 h-4"></i> CSV出力
                </button>
                <button v-if="results.length" @click="clearResults" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 border border-gray-200 rounded-md px-3 py-1.5 hover:bg-gray-50">
                    <i data-lucide="trash-2" class="w-4 h-4"></i> クリア
                </button>
            </div>
        </div>
        <div v-if="results.length === 0" class="p-10 text-center text-gray-400">
            取り込み（①）か、1件ずつ（②）で調べると、ここに結果が表示されます。
        </div>
        <ul v-else class="divide-y divide-gray-100">
            <li v-for="(r, i) in results" :key="i" class="p-5 hover:bg-gray-50">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span v-if="r.industry" class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ r.industry }}</span>
                            <span class="text-lg font-bold text-gray-900">
                                <span v-if="r.pending" class="inline-flex items-center gap-1 text-gray-400"><i data-lucide="loader" class="w-4 h-4 sync-spin"></i>{{ r.name }}</span>
                                <span v-else>{{ r.name }}</span>
                            </span>
                        </div>
                        <p v-if="r.address || r.phone" class="text-sm text-gray-500 mt-0.5">{{ r.address }}<span v-if="r.phone"> ／ {{ r.phone }}</span></p>
                    </div>
                    <span v-if="!r.pending"
                          :class="['text-xs font-bold px-2.5 py-1 rounded-full shrink-0 border', r.found ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-gray-100 text-gray-400 border-gray-200']">
                        {{ r.found ? '独自ドメイン有' : '空白' }}
                    </span>
                </div>

                <div class="mt-3">
                    <template v-if="r.found">
                        <a :href="r.topUrl" target="_blank" rel="noopener" class="text-xl font-bold text-emerald-700 hover:underline break-all">{{ r.topUrl }}</a>
                        <div class="text-sm text-gray-600 mt-1 break-all">
                            <span class="text-gray-400">会社概要等：</span>
                            <a :href="r.pageUrl" target="_blank" rel="noopener" class="text-blue-600 hover:underline">{{ r.pageUrl }}</a>
                        </div>
                        <div v-if="r.evidence" class="text-xs text-gray-400 mt-1">どこで判断：{{ r.evidence }}</div>
                    </template>
                    <template v-else>
                        <p class="text-sm text-gray-500 bg-gray-50 border border-gray-100 rounded-lg p-3 break-all">{{ r.evidence || (r.pending ? '調査中…' : '（空白）') }}</p>
                    </template>
                </div>
            </li>
        </ul>
    </div>

    <!-- 除外ドメイン -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">
            <h3 class="font-bold text-gray-800 flex items-center gap-2"><i data-lucide="ban" class="w-5 h-5 text-rose-500"></i>除外ドメイン</h3>
            <span class="text-xs text-gray-400">SNS・予約・ポータル・求人・口コミ等＋取り込み済みの除外リスト（約4万件）は自動で除外されます</span>
        </div>
        <?php if ($canEdit): ?>
        <p class="text-xs text-gray-500 mb-2">ここに追加したドメインも検索結果から除外します（1行に1つ。例: <code class="bg-gray-100 px-1 rounded">example.com</code>）。「会社のHPでないサイト」が出たら、ここに足して保存すれば次から除外されます。</p>
        <textarea v-model="excludeText" rows="5" placeholder="example.com&#10;sample.co.jp" class="w-full border border-gray-300 rounded-md p-3 text-sm font-mono focus:ring-emerald-500 focus:border-emerald-500"></textarea>
        <div class="mt-3 flex items-center gap-3">
            <button @click="saveExclude" class="inline-flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white text-sm font-medium px-4 py-2 rounded-lg">
                <i data-lucide="save" class="w-4 h-4"></i> 保存
            </button>
            <span class="text-xs text-gray-400">現在 {{ excludeCount }} 件を追加除外中</span>
        </div>
        <?php else: ?>
        <ul class="text-sm text-gray-600 list-disc pl-5 space-y-0.5">
            <li v-for="(d, i) in store.excludeDomains" :key="i">{{ d }}</li>
            <li v-if="!store.excludeDomains.length" class="list-none text-gray-400">（追加の除外ドメインはありません）</li>
        </ul>
        <?php endif; ?>
    </div>

</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
