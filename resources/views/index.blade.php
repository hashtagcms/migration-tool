<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HashtagCms Migration Tool</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;900&display=swap');
        body { font-family: 'Outfit', sans-serif; background-color: #f8fafc; }
        .glass { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
        .animate-float { animation: float 6s ease-in-out infinite; }
        @keyframes float { 0% { transform: translateY(0px); } 50% { transform: translateY(-10px); } 100% { transform: translateY(0px); } }
        .animate-fadeIn { animation: fadeIn 0.35s ease-out both; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
@verbatim
    <div id="app" class="w-full max-w-2xl">
        <div v-if="step === 'connect'" class="glass rounded-3xl shadow-2xl border border-white overflow-hidden animate-fadeIn">
            <!-- Header -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-700 p-8 text-white relative">
                <div class="absolute top-0 right-0 p-6 opacity-10 animate-float text-6xl">
                    <i class="fa fa-database"></i>
                </div>
                <h1 class="text-3xl font-black tracking-tight mb-2">Migration Wizard</h1>
                <p class="text-blue-100 font-medium">Connect your source database to begin the migration process.</p>
            </div>

            <!-- Form Body -->
            <div class="p-8 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Database Host</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"><i class="fa fa-server"></i></span>
                            <input v-model="form.host" class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-600 outline-none transition-all font-bold text-gray-700" placeholder="127.0.0.1">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Port</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"><i class="fa fa-plug"></i></span>
                            <input v-model="form.port" class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-600 outline-none transition-all font-bold text-gray-700" placeholder="3306">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Database Name</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"><i class="fa fa-database"></i></span>
                            <input v-model="form.database" class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-600 outline-none transition-all font-bold text-gray-700" placeholder="source_cms_db">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Site Table Prefix (Optional)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"><i class="fa fa-indent"></i></span>
                            <input v-model="form.prefix" class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-600 outline-none transition-all font-bold text-gray-700" placeholder="htcms_">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Username</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"><i class="fa fa-user"></i></span>
                            <input v-model="form.username" class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-600 outline-none transition-all font-bold text-gray-700" placeholder="root">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Password</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"><i class="fa fa-key"></i></span>
                            <input v-model="form.password" type="password" class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-600 outline-none transition-all font-bold text-gray-700" placeholder="••••••••">
                        </div>
                    </div>
                </div>

                <!-- Footer Section -->
                <div class="pt-6 border-t border-gray-100 flex flex-col items-center gap-4">
                    <button 
                        @click="testConnection" 
                        :disabled="loading"
                        class="w-full py-4 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-2xl shadow-xl shadow-blue-500/20 transition-all hover:-translate-y-1 active:scale-95 flex items-center justify-center gap-3 disabled:opacity-50"
                    >
                        <span v-if="loading"><i class="fa fa-circle-notch fa-spin"></i> Testing Link...</span>
                        <span v-else>Establish Connection <i class="fa fa-arrow-right ml-1"></i></span>
                    </button>
                    <div v-if="status" :class="status.success ? 'text-green-600 bg-green-50' : 'text-red-600 bg-red-50'" class="w-full p-4 rounded-xl text-sm font-bold flex items-center gap-3 animate-shake">
                        <i :class="status.success ? 'fa fa-check-circle' : 'fa fa-exclamation-circle'"></i>
                        {{ status.message }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Wizard Step 2 (Discovery / Analysis) -->
        <div v-else-if="step === 'discovery'" class="glass rounded-3xl shadow-2xl border border-white overflow-hidden animate-fadeIn">
            <div class="bg-gradient-to-r from-emerald-600 to-teal-700 p-8 text-white">
                <h2 class="text-3xl font-black tracking-tight mb-2">Source Insight</h2>
                <p class="text-emerald-100 font-medium">We've identified the following entities in your source database.</p>
            </div>

            <div class="p-8 space-y-8">
                <!-- Summary Stats -->
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <div v-for="(count, label) in summary" :key="label" class="bg-gray-50 border border-gray-100 p-4 rounded-2xl">
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">{{ label.replace('_', ' ') }}</div>
                        <div class="text-2xl font-black text-gray-800">{{ count }}</div>
                    </div>
                </div>

                <!-- Dependency Warnings -->
                <div v-if="packageWarnings.length > 0" class="bg-amber-50 border border-amber-200 rounded-2xl p-6 space-y-3 animate-shake">
                    <div class="flex items-center gap-3 text-amber-700">
                        <i class="fa fa-triangle-exclamation text-xl"></i>
                        <h3 class="font-black text-sm uppercase tracking-wider">Missing Dependencies Detected</h3>
                    </div>
                    <p class="text-xs text-amber-600 font-medium">The source project uses packages that are missing in this installation. You should install them after migration:</p>
                    <div class="bg-gray-900 rounded-xl p-4 font-mono text-[10px] text-amber-400">
                        composer require {{ packageWarnings.join(' ') }}
                    </div>
                </div>

                <!-- Site Selection -->
                <div class="space-y-4">
                    <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Select Site to Migrate</label>
                    <div class="grid grid-cols-1 gap-3">
                        <div 
                            v-for="site in sites" 
                            :key="site.id" 
                            @click="selectedSite = site.id"
                            :class="selectedSite === site.id ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500/20' : 'border-gray-100 bg-gray-50 hover:bg-white hover:shadow-md'"
                            class="p-4 rounded-xl border-2 cursor-pointer transition-all flex items-center justify-between"
                        >
                            <div class="flex items-center gap-4">
                                <div :class="selectedSite === site.id ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-500'" class="w-10 h-10 rounded-lg flex items-center justify-center transition-colors">
                                    <i class="fa fa-globe"></i>
                                </div>
                                <div>
                                    <div class="font-black text-gray-800">{{ site.name }}</div>
                                    <div class="text-xs font-bold text-gray-400">{{ site.domain }}</div>
                                </div>
                            </div>
                            <div v-if="selectedSite === site.id" class="text-emerald-500 text-xl">
                                <i class="fa fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="pt-6 border-t border-gray-100 flex items-center justify-between">
                    <button @click="step='connect'" class="text-sm font-black text-gray-400 hover:text-gray-600 transition-colors uppercase tracking-widest">
                        <i class="fa fa-arrow-left mr-1"></i> Back to Connection
                    </button>
                    <button 
                        @click="prepareMigration"
                        :disabled="!selectedSite || loading"
                        class="px-10 py-4 bg-emerald-600 hover:bg-emerald-700 text-white font-black rounded-2xl shadow-xl shadow-emerald-500/20 transition-all hover:-translate-y-1 active:scale-95 disabled:opacity-50"
                    >
                        <span v-if="loading"><i class="fa fa-circle-notch fa-spin"></i> Preparing...</span>
                        <span v-else>Prepare Migration Layer <i class="fa fa-magic ml-2"></i></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Wizard Step 3 (Review / Configuration) -->
        <div v-else-if="step === 'review'" class="glass rounded-3xl shadow-2xl border border-white overflow-hidden animate-fadeIn">
            <div class="bg-gradient-to-r from-blue-700 to-blue-900 p-8 text-white">
                <h2 class="text-3xl font-black tracking-tight mb-2">Final Review</h2>
                <p class="text-blue-100 font-medium">Configure and verify the migration payload for <strong>{{ getSelectedSite()?.name }}</strong>.</p>
            </div>

            <div class="p-8 space-y-8">
                <!-- Detailed Stats -->
                <div class="space-y-4">
                    <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Migration Payload</label>
                    <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 grid grid-cols-2 gap-y-6">
                        <div v-for="(count, key) in siteDetails" :key="key" class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center text-xs">
                                <i class="fa fa-check"></i>
                            </div>
                            <div>
                                <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">{{ key.replace('_', ' ') }}</div>
                                <div class="font-black text-gray-800">{{ count }} Items</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Toggles & Advanced Settings -->
                <div class="space-y-4">
                     <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Migration Settings</label>
                     <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                         <!-- Media Toggle -->
                         <div class="flex flex-col p-4 bg-gray-50 rounded-xl hover:bg-white border-2 border-transparent hover:border-blue-100 transition-all group">
                             <div class="flex items-center justify-between mb-2">
                                 <div class="flex items-center gap-3">
                                     <i class="fa fa-image text-gray-400 group-hover:text-blue-500"></i>
                                     <span class="font-bold text-gray-700">Migrate Media</span>
                                 </div>
                                 <input type="checkbox" v-model="form.copy_media" class="w-5 h-5 rounded border-gray-200 text-blue-600 focus:ring-blue-500">
                             </div>
                             <p class="text-[10px] text-gray-400 font-medium">Syncs public/assets. Requires source_root_path.</p>
                         </div>

                         <!-- Template Sync (Independent) -->
                         <div class="flex flex-col p-4 bg-indigo-50/30 rounded-xl hover:bg-white border-2 border-transparent hover:border-indigo-100 transition-all group">
                             <div class="flex items-center justify-between mb-2">
                                 <div class="flex items-center gap-3">
                                     <i class="fa fa-file-code text-indigo-400 group-hover:text-indigo-600"></i>
                                     <span class="font-bold text-gray-700">Template Sync</span>
                                 </div>
                                 <button 
                                    @click="runTemplateSync" 
                                    :disabled="loadingTemplates || !form.source_root_path"
                                    class="text-[10px] font-black uppercase tracking-tighter bg-indigo-600 text-white px-3 py-1 rounded-full hover:bg-indigo-700 disabled:opacity-50"
                                 >
                                    <span v-if="loadingTemplates"><i class="fa fa-circle-notch fa-spin"></i></span>
                                    <span v-else>Sync Now</span>
                                 </button>
                             </div>
                             <p class="text-[10px] text-gray-400 font-medium">Copies theme/module blade files. Requires source_root_path.</p>
                         </div>
                     </div>

                     <!-- Source Path (Always visible if needed for media or templates) -->
                     <div class="p-4 bg-blue-50/50 rounded-xl border border-blue-100 animate-fadeIn">
                         <label class="block text-[10px] font-black text-blue-400 uppercase tracking-widest mb-2">Source Installation Path (Local Root)</label>
                         <div class="relative">
                             <span class="absolute left-4 top-1/2 -translate-y-1/2 text-blue-300 text-xs"><i class="fa fa-folder-open"></i></span>
                             <input 
                                 type="text" 
                                 v-model="form.source_root_path" 
                                 placeholder="/var/www/old-cms"
                                 class="w-full bg-white border border-blue-200 rounded-lg pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none font-mono"
                             >
                         </div>
                     </div>

                     <!-- Conflict Strategy -->
                     <div class="space-y-2">
                         <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">Conflict Resolution Strategy</label>
                         <select v-model="form.conflict_strategy" class="w-full p-4 bg-gray-50 rounded-xl border-2 border-transparent hover:border-blue-100 outline-none font-bold text-gray-700 transition-all">
                             <option value="terminate">Terminate (Safest)</option>
                             <option value="overwrite">Overwrite (DANGEROUS)</option>
                             <option value="rename">Rename (Create Copy)</option>
                         </select>
                     </div>
                </div>

                <!-- Template Sync Result Message -->
                <div v-if="templateStatus" :class="templateStatus.success ? 'bg-green-50 border-green-100 text-green-700' : 'bg-red-50 border-red-100 text-red-700'" class="p-4 rounded-xl border text-xs font-bold animate-fadeIn">
                    <i :class="templateStatus.success ? 'fa fa-check-circle' : 'fa fa-exclamation-circle'" class="mr-2"></i>
                    {{ templateStatus.message }}
                    <div v-if="templateStatus.details" class="mt-2 text-[10px] space-y-1 font-mono opacity-80">
                        <div v-for="theme in templateStatus.details" :key="theme.theme">
                            Theme: {{ theme.theme }} ({{ theme.directory }})
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="pt-6 border-t border-gray-100 flex items-center justify-between">
                    <button @click="step='discovery'" class="text-sm font-black text-gray-400 hover:text-gray-600 transition-colors uppercase tracking-widest">
                        <i class="fa fa-arrow-left mr-1"></i> Back to Sites
                    </button>
                    <button
                        @click="goToPreflight"
                        :disabled="loading"
                        class="px-10 py-4 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-2xl shadow-xl shadow-blue-500/20 transition-all hover:-translate-y-1 active:scale-95 disabled:opacity-50"
                    >
                        <span v-if="loading"><i class="fa fa-circle-notch fa-spin"></i> Checking...</span>
                        <span v-else>Run Pre-flight Checks <i class="fa fa-clipboard-check ml-2"></i></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Wizard Step 4 (Preflight Requirements Check) -->
        <div v-else-if="step === 'preflight'" class="glass rounded-3xl shadow-2xl border border-white overflow-hidden animate-fadeIn">
            <div class="bg-gradient-to-r from-violet-700 to-indigo-800 p-8 text-white">
                <h2 class="text-3xl font-black tracking-tight mb-2">Pre-flight Check</h2>
                <p class="text-violet-100 font-medium">Verifying all requirements before migration starts.</p>
            </div>

            <div class="p-8 space-y-6">

                <!-- Loading state -->
                <div v-if="preflightLoading" class="flex flex-col items-center justify-center py-12 space-y-4">
                    <i class="fa fa-circle-notch fa-spin text-4xl text-violet-500"></i>
                    <p class="text-gray-500 font-bold">Running checks...</p>
                </div>

                <!-- Results -->
                <div v-else-if="preflightChecks.length" class="space-y-3">
                    <div
                        v-for="(check, i) in preflightChecks"
                        :key="i"
                        class="flex items-start gap-4 p-4 rounded-2xl border"
                        :class="{
                            'bg-green-50 border-green-200': check.status === 'pass',
                            'bg-yellow-50 border-yellow-200': check.status === 'warning',
                            'bg-red-50 border-red-200': check.status === 'fail'
                        }"
                    >
                        <div class="mt-0.5 w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0"
                            :class="{
                                'bg-green-500': check.status === 'pass',
                                'bg-yellow-400': check.status === 'warning',
                                'bg-red-500': check.status === 'fail'
                            }"
                        >
                            <i class="text-white text-xs fa"
                                :class="{
                                    'fa-check': check.status === 'pass',
                                    'fa-exclamation': check.status === 'warning',
                                    'fa-times': check.status === 'fail'
                                }"
                            ></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-black text-sm text-gray-800">{{ check.label }}</p>
                                <span v-if="check.critical && check.status === 'fail'" class="text-xs font-black bg-red-500 text-white px-2 py-0.5 rounded-full">BLOCKING</span>
                                <span v-if="check.status === 'warning'" class="text-xs font-black bg-yellow-400 text-white px-2 py-0.5 rounded-full">WARNING</span>
                            </div>
                            <p class="text-sm text-gray-500 mt-0.5">{{ check.message }}</p>
                        </div>
                    </div>

                    <!-- Overall verdict banner -->
                    <div
                        class="mt-4 p-4 rounded-2xl font-black text-sm flex items-center gap-3"
                        :class="preflightCanProceed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                    >
                        <i class="fa text-lg" :class="preflightCanProceed ? 'fa-circle-check text-green-500' : 'fa-ban text-red-500'"></i>
                        <span v-if="preflightCanProceed">All critical checks passed. You can safely proceed.</span>
                        <span v-else>One or more critical requirements failed. Fix the issues above before migrating.</span>
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-100 flex items-center justify-between">
                    <button @click="step='review'" class="text-sm font-black text-gray-400 hover:text-gray-600 transition-colors uppercase tracking-widest">
                        <i class="fa fa-arrow-left mr-1"></i> Back to Review
                    </button>
                    <button
                        @click="runMigration"
                        :disabled="loading || !preflightCanProceed || preflightLoading"
                        class="px-10 py-4 font-black rounded-2xl shadow-xl transition-all hover:-translate-y-1 active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed"
                        :class="preflightCanProceed
                            ? 'bg-green-600 hover:bg-green-700 text-white shadow-green-500/20'
                            : 'bg-gray-300 text-gray-500'"
                    >
                        <span v-if="loading"><i class="fa fa-circle-notch fa-spin"></i> Starting...</span>
                        <span v-else>Start Migration <i class="fa fa-rocket ml-2"></i></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Wizard Step 5 (Progress Monitoring) -->
        <div v-else-if="step === 'migrating'" class="glass rounded-3xl shadow-2xl border border-white overflow-hidden animate-fadeIn">
            <div class="p-12 text-center space-y-8">
                <div class="relative w-24 h-24 mx-auto">
                    <div class="absolute inset-0 border-4 border-indigo-100 rounded-full"></div>
                    <div class="absolute inset-0 border-4 border-indigo-600 rounded-full border-t-transparent animate-spin"></div>
                    <div class="absolute inset-0 flex items-center justify-center font-black text-indigo-600 text-xl">{{ progress }}%</div>
                </div>
                <div>
                     <h2 class="text-2xl font-black text-gray-800 tracking-tight">Migrating Data...</h2>
                     <p class="text-gray-500 font-medium">Please do not close this window. We are performing the ETL process.</p>
                </div>
                <div class="w-full bg-gray-100 h-3 rounded-full overflow-hidden">
                    <div :style="{ width: progress + '%' }" class="h-full bg-indigo-600 transition-all duration-500"></div>
                </div>
                <div class="text-xs font-black text-indigo-400 uppercase tracking-widest">{{ migrationMessage }}</div>
            </div>
        </div>

        <!-- Wizard Step 5 (Success / Report) -->
        <div v-else-if="step === 'success'" class="glass rounded-3xl shadow-2xl border border-white overflow-hidden animate-fadeIn">
            <div class="bg-gradient-to-r from-purple-600 to-indigo-700 p-12 text-center text-white relative">
                <div class="absolute top-0 left-0 w-full h-full opacity-10 pointer-events-none">
                    <i class="fa fa-sparkles text-8xl absolute top-4 left-4"></i>
                    <i class="fa fa-check-circle text-8xl absolute bottom-4 right-4"></i>
                </div>
                <div class="w-20 h-20 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-6 text-4xl animate-bounce">
                    <i class="fa fa-check"></i>
                </div>
                <h2 class="text-4xl font-black tracking-tight mb-2">Migration Successful!</h2>
                <p class="text-purple-100 font-medium opacity-80">The data has been safely moved to your target database.</p>
            </div>

            <div class="p-8 space-y-6">
                <!-- Log Summary -->
                <div class="space-y-4">
                    <label class="text-xs font-black text-gray-400 uppercase tracking-widest ml-1">Migration Report</label>
                    <div class="bg-gray-900 rounded-2xl p-6 text-emerald-400 font-mono text-xs space-y-2 max-h-[200px] overflow-y-auto">
                        <div v-for="(res, stepName) in migrationResults" :key="stepName">
                            <span class="text-gray-500">[{{ stepName }}]</span> {{ JSON.stringify(res) }}
                        </div>
                        <div class="pt-4 text-white font-bold">&gt; Process finished at {{ new Date().toLocaleTimeString() }}</div>
                    </div>
                </div>

                <!-- Next Steps -->
                <div class="flex gap-4">
                    <a href="/admin" class="flex-1 py-4 bg-gray-100 hover:bg-gray-200 text-gray-800 text-center font-black rounded-2xl transition-all">
                        Go to Admin Panel
                    </a>
                    <button @click="window.location.reload()" class="flex-1 py-4 bg-indigo-600 hover:bg-indigo-700 text-white font-black rounded-2xl shadow-lg transition-all">
                        Start Another Migration
                    </button>
                </div>
            </div>
        </div>
    </div>
@endverbatim

    <script>
        const { createApp, ref, reactive } = Vue;
        axios.defaults.headers.common['X-CSRF-TOKEN'] = '{{ csrf_token() }}';
        
        createApp({
            setup() {
                const step = ref('connect');
                const loading = ref(false);
                const status = ref(null);
                const templateStatus = ref(null);
                const loadingTemplates = ref(false);
                const summary = ref({});
                const packageWarnings = ref([]);
                const sites = ref([]);
                const selectedSite = ref(null);
                const siteDetails = ref({});
                const migrationResults = ref({});
                const progress = ref(0);
                const migrationMessage = ref('Initializing...');
                const preflightChecks = ref([]);
                const preflightLoading = ref(false);
                const preflightCanProceed = ref(false);
                let pollInterval = null;
                
                const form = reactive({
                    host: '127.0.0.1',
                    port: '3306',
                    database: '',
                    username: 'root',
                    password: '',
                    prefix: '',
                    copy_media: true,
                    source_root_path: '',
                    conflict_strategy: 'terminate'
                });

                const getSelectedSite = () => sites.value.find(s => s.id === selectedSite.value);

                const testConnection = async () => {
                   // existing testConnection logic...
                    loading.value = true;
                    status.value = null;
                    try {
                        const response = await axios.post('{{ route("migration.test-connection") }}', form);
                        if (response.data.success) {
                            const analysis = await axios.post('{{ route("migration.analyze") }}', form);
                            if (analysis.data.success) {
                                summary.value = analysis.data.summary;
                                packageWarnings.value = analysis.data.package_warnings || [];
                                sites.value = analysis.data.sites_list;
                                step.value = 'discovery';
                            } else {
                                status.value = { success: false, message: analysis.data.message };
                            }
                        } else {
                            status.value = response.data;
                        }
                    } catch (e) {
                        status.value = { success: false, message: e.response?.data?.message || 'Network error occurred.' };
                    } finally {
                        loading.value = false;
                    }
                };

                const prepareMigration = async () => {
                    loading.value = true;
                    try {
                        const response = await axios.post('{{ route("migration.site-details") }}', { ...form, site_id: selectedSite.value });
                        if (response.data.success) {
                            siteDetails.value = response.data.details;
                            step.value = 'review';
                        }
                    } catch (e) {
                        alert('Failed to fetch site details');
                    } finally {
                        loading.value = false;
                    }
                };

                const runMigration = async () => {
                    loading.value = true;
                    try {
                        const response = await axios.post('{{ route("migration.run-migration") }}', { ...form, site_id: selectedSite.value });
                        if (response.data.success) {
                            step.value = 'migrating';
                            startPolling(response.data.job_id);
                        } else {
                            alert('Migration failed: ' + response.data.message);
                        }
                    } catch (e) {
                         alert('Network error during migration');
                    } finally {
                        loading.value = false;
                    }
                };

                const goToPreflight = async () => {
                    preflightChecks.value = [];
                    preflightCanProceed.value = false;
                    preflightLoading.value = true;
                    step.value = 'preflight';
                    try {
                        const response = await axios.post('{{ route("migration.check-requirements") }}', {
                            site_id: selectedSite.value,
                            copy_media: form.copy_media,
                            source_root_path: form.source_root_path,
                        });
                        if (response.data.success) {
                            preflightChecks.value = response.data.checks;
                            preflightCanProceed.value = response.data.can_proceed;
                        } else {
                            preflightChecks.value = [{ label: 'Pre-flight Check', status: 'fail', message: response.data.message, critical: true }];
                        }
                    } catch (e) {
                        preflightChecks.value = [{ label: 'Pre-flight Check', status: 'fail', message: e.response?.data?.message || 'Network error during pre-flight check.', critical: true }];
                    } finally {
                        preflightLoading.value = false;
                    }
                };

                const runTemplateSync = async () => {
                    loadingTemplates.value = true;
                    templateStatus.value = null;
                    try {
                        const response = await axios.post('{{ route("migration.migrate-templates") }}', { 
                            source_root: form.source_root_path, 
                            site_id: selectedSite.value 
                        });
                        templateStatus.value = response.data;
                    } catch (e) {
                        templateStatus.value = { success: false, message: e.response?.data?.message || 'Failed to sync templates' };
                    } finally {
                        loadingTemplates.value = false;
                    }
                };

                const startPolling = (jobId) => {
                    pollInterval = setInterval(async () => {
                        try {
                            const progressUrl = '{{ route("migration.check-progress", ["job_id" => "__JOB__"]) }}'.replace('__JOB__', jobId);
                            const response = await axios.get(progressUrl);
                            if (response.data.success) {
                                progress.value = response.data.progress;
                                migrationMessage.value = response.data.status.toUpperCase();
                                
                                if (response.data.status === 'completed') {
                                    clearInterval(pollInterval);
                                    migrationResults.value = response.data.results;
                                    step.value = 'success';
                                } else if (response.data.status === 'failed') {
                                    clearInterval(pollInterval);
                                    alert('Migration failed: ' + response.data.message);
                                    step.value = 'review';
                                }
                            }
                        } catch (e) {
                            console.error('Polling error', e);
                        }
                    }, 2000);
                };

                return { step, form, loading, loadingTemplates, status, templateStatus, summary, packageWarnings, sites, selectedSite, siteDetails, migrationResults, progress, migrationMessage, preflightChecks, preflightLoading, preflightCanProceed, testConnection, prepareMigration, goToPreflight, runMigration, runTemplateSync, getSelectedSite };

            }
        }).mount('#app');
    </script>
</body>
</html>
