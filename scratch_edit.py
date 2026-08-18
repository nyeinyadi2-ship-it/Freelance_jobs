import sys

filepath = 'c:\\wamp64\\www\\freelancer_job\\index.php'

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove PHP fetch block
start_marker1 = "// Fetch latest jobs (exclude direct hire)"
end_marker1 = "    LIMIT $fl_per_page OFFSET $fl_offset\n\");\nif ($r) {\n    while ($row = $r->fetch_assoc()) {\n        $top_freelancers[] = $row;\n    }\n}\n"

idx1 = content.find(start_marker1)
idx2 = content.find(end_marker1) + len(end_marker1)

if idx1 != -1 and idx2 != -1 and idx1 < idx2:
    content = content[:idx1] + content[idx2:]
else:
    print("Could not find PHP block to remove")
    sys.exit(1)

# 2. Replace HTML sections
start_marker2 = "    <!-- ===== FEATURED JOBS ===== -->"
end_marker2 = "    <!-- ===== FOOTER ===== -->"

idx3 = content.find(start_marker2)
idx4 = content.find(end_marker2)

if idx3 != -1 and idx4 != -1 and idx3 < idx4:
    new_how_it_works = """    <!-- ===== HOW IT WORKS ===== -->
    <section class="py-28 bg-gradient-to-b from-indigo-50/30 to-white/50 dark:from-slate-900/50 dark:to-slate-800/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-16 reveal">
                <span class="section-eyebrow justify-center">How It Works</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white mb-4">Simple Process for Everyone</h2>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto text-lg">Whether you're hiring or looking for work, our platform makes it effortless.</p>
            </div>

            <div class="grid md:grid-cols-2 gap-12 lg:gap-20">
                <!-- Freelancer Flow -->
                <div class="reveal reveal-d1">
                    <div class="flex items-center gap-4 mb-8">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white">For Freelancers</h3>
                    </div>
                    <div class="space-y-8 relative before:absolute before:inset-0 before:ml-6 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-indigo-200 dark:before:via-indigo-800 before:to-transparent">
                        <div class="relative flex items-start gap-6">
                            <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-4 border-indigo-100 dark:border-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold z-10 shadow-sm shrink-0">1</div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Find a Job</h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Browse thousands of jobs that match your skills.</p>
                            </div>
                        </div>
                        <div class="relative flex items-start gap-6">
                            <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-4 border-indigo-100 dark:border-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold z-10 shadow-sm shrink-0">2</div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Apply</h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Submit a compelling proposal to the client.</p>
                            </div>
                        </div>
                        <div class="relative flex items-start gap-6">
                            <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-4 border-indigo-100 dark:border-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold z-10 shadow-sm shrink-0">3</div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Get Hired</h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Interview with the client and get the contract.</p>
                            </div>
                        </div>
                        <div class="relative flex items-start gap-6">
                            <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-4 border-indigo-100 dark:border-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold z-10 shadow-sm shrink-0">4</div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Complete Work</h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Deliver high quality work and get paid securely.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Company Flow -->
                <div class="reveal reveal-d2 mt-12 md:mt-0">
                    <div class="flex items-center gap-4 mb-8">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white">For Companies</h3>
                    </div>
                    <div class="space-y-8 relative before:absolute before:inset-0 before:ml-6 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-emerald-200 dark:before:via-emerald-800 before:to-transparent">
                        <div class="relative flex items-start gap-6">
                            <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-4 border-emerald-100 dark:border-emerald-900 flex items-center justify-center text-emerald-600 dark:text-emerald-400 font-bold z-10 shadow-sm shrink-0">1</div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Post a Job</h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Describe your project and the skills required.</p>
                            </div>
                        </div>
                        <div class="relative flex items-start gap-6">
                            <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-4 border-emerald-100 dark:border-emerald-900 flex items-center justify-center text-emerald-600 dark:text-emerald-400 font-bold z-10 shadow-sm shrink-0">2</div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Receive Applications</h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Get proposals from talented freelancers.</p>
                            </div>
                        </div>
                        <div class="relative flex items-start gap-6">
                            <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-4 border-emerald-100 dark:border-emerald-900 flex items-center justify-center text-emerald-600 dark:text-emerald-400 font-bold z-10 shadow-sm shrink-0">3</div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Hire Freelancer</h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Select the best fit and begin collaboration.</p>
                            </div>
                        </div>
                        <div class="relative flex items-start gap-6">
                            <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 border-4 border-emerald-100 dark:border-emerald-900 flex items-center justify-center text-emerald-600 dark:text-emerald-400 font-bold z-10 shadow-sm shrink-0">4</div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Complete Payment</h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Release funds when you are 100% satisfied.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

"""
    content = content[:idx3] + new_how_it_works + content[idx4:]
else:
    print("Could not find HTML sections to replace")
    sys.exit(1)

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)

print("Successfully updated index.php")
