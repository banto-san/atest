<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$data       = load_data();
$pageTitle  = 'アカウント管理';
$currentNav = 'accounts';
$pageScript = 'page-accounts.js';

require __DIR__ . '/layout_top.php';
?>
<div class="max-w-5xl mx-auto space-y-6">
    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h3 class="text-xl font-bold flex items-center text-gray-800">
                <i data-lucide="users" class="w-6 h-6 mr-2 text-blue-600"></i>
                アカウント・権限管理
            </h3>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="md:col-span-1 bg-gray-50 p-4 rounded-lg border border-gray-200 h-fit">
                <h4 class="font-bold text-gray-700 mb-4">{{ editingUser ? 'アカウント情報を編集' : '新規アカウント追加' }}</h4>
                <form @submit.prevent="saveUser" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">表示名</label>
                        <input type="text" v-model="userForm.name" required placeholder="山田 太郎" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ログインID</label>
                        <input type="text" v-model="userForm.loginId" required placeholder="yamada123" class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">パスワード</label>
                        <input type="text" v-model="userForm.password" required class="w-full border border-gray-300 rounded-md p-2 text-sm bg-white focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="flex space-x-2 pt-2">
                        <button type="submit" :disabled="store.isSyncing" class="flex-1 bg-blue-600 text-white font-medium py-2 px-4 rounded-md hover:bg-blue-700 transition shadow-sm disabled:opacity-50">
                            {{ editingUser ? '更新する' : '追加する' }}
                        </button>
                        <button v-if="editingUser" type="button" @click="cancelUserEdit" class="flex-1 bg-gray-300 text-gray-700 font-medium py-2 px-4 rounded-md hover:bg-gray-400 transition shadow-sm">
                            キャンセル
                        </button>
                    </div>
                </form>
            </div>

            <div class="md:col-span-2">
                <div class="overflow-hidden border border-gray-200 rounded-lg">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-700 bg-gray-100 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 font-bold">表示名</th>
                                <th class="px-4 py-3 font-bold">ログインID</th>
                                <th class="px-4 py-3 font-bold">パスワード</th>
                                <th class="px-4 py-3 font-bold text-center w-24">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in systemUsers" :key="user.id" class="bg-white border-b hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ user.name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ user.loginId }}</td>
                                <td class="px-4 py-3 text-gray-400">••••</td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <button @click="editUser(user)" class="text-blue-600 hover:bg-blue-50 p-1.5 rounded" title="編集">
                                            <i data-lucide="edit" class="w-4 h-4"></i>
                                        </button>
                                        <button @click="deleteUser(user.id)" :disabled="systemUsers.length <= 1" class="text-red-600 hover:bg-red-50 p-1.5 rounded disabled:opacity-30" title="削除">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 mt-3 flex items-center">
                    <i data-lucide="info" class="w-4 h-4 mr-1"></i>
                    セキュリティ上、必ず1つ以上の管理アカウントを残す必要があります。
                </p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
