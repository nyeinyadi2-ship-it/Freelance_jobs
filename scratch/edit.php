<?php
$file = 'c:\\wamp64\\www\\freelancer_job\\index.php';
$content = file_get_contents($file);

$startMarker = '    <!-- ===== FEATURED JOBS ===== -->';
$endMarker = '    <!-- ===== FOOTER ===== -->';

$startPos = strpos($content, $startMarker);
$endPos = strpos($content, $endMarker);

if ($startPos !== false && $endPos !== false) {
    $before = substr($content, 0, $startPos);
    $after = substr($content, $endPos);

    $howItWorks = <<<HTML
    <!-- ===== HOW IT WORKS ===== -->
    <section class="py-28 bg-gradient-to-b from-indigo-50/30 to-white/50 dark:from-slate-900/50 dark:to-slate-800/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-16 reveal">
                <span class="section-eyebrow justify-center">How It Works</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white mb-4">Simple Processes for Everyone</h2>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto text-lg">Whether you're hiring or looking for work, our platform makes it effortless</p>
            </div>

            <div class="grid md:grid-cols-2 gap-12 lg:gap-16">
                <!-- Freelancer Process -->
                <div class="reveal reveal-d1 bg-white dark:bg-slate-800 rounded-3xl p-8 lg:p-10 border border-gray-100 dark:border-gray-700 shadow-xl shadow-primary-500/5">
                    <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-8 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-md">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </div>
                        For Freelancers
                    </h3>
                    <div class="space-y-8 relative before:absolute before:inset-y-0 before:left-5 before:w-px before:bg-gray-100 dark:before:bg-gray-700">
                        <?php
                        \$freelancer_steps = [
                            ['num' => '1', 'title' => 'Find a Job', 'desc' => 'Browse through hundreds of available projects matching your skills.'],
                            ['num' => '2', 'title' => 'Apply', 'desc' => 'Submit your post and showcase your past experience.'],
                            ['num' => '3', 'title' => 'Get Hired', 'desc' => 'Communicate with the client and start working on the project.'],
                            ['num' => '4', 'title' => 'Complete Work', 'desc' => 'Deliver the project and receive secure payment instantly.'],
                        ];
                        foreach (\$freelancer_steps as \$step):
                        ?>
                        <div class="flex gap-5 relative z-10">
                            <div class="w-10 h-10 rounded-full bg-indigo-50 dark:bg-indigo-900/40 border-4 border-white dark:border-slate-800 text-indigo-600 dark:text-indigo-400 flex-shrink-0 flex items-center justify-center font-extrabold text-sm shadow-sm">
                                <?= \$step['num'] ?>
                            </div>
                            <div class="pt-1.5">
                                <h4 class="font-bold text-gray-900 dark:text-white text-lg mb-1.5"><?= \$step['title'] ?></h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed"><?= \$step['desc'] ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Company Process -->
                <div class="reveal reveal-d2 bg-white dark:bg-slate-800 rounded-3xl p-8 lg:p-10 border border-gray-100 dark:border-gray-700 shadow-xl shadow-accent-500/5">
                    <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-8 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-500 to-accent-600 flex items-center justify-center shadow-md">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        </div>
                        For Companies
                    </h3>
                    <div class="space-y-8 relative before:absolute before:inset-y-0 before:left-5 before:w-px before:bg-gray-100 dark:before:bg-gray-700">
                        <?php
                        \$company_steps = [
                            ['num' => '1', 'title' => 'Post a Job', 'desc' => 'Describe your project requirements and set a budget easily.'],
                            ['num' => '2', 'title' => 'Receive Applications', 'desc' => 'Review posts from talented freelancers globally.'],
                            ['num' => '3', 'title' => 'Hire Freelancer', 'desc' => 'Select the best fit for your needs and begin collaboration.'],
                            ['num' => '4', 'title' => 'Complete Payment', 'desc' => 'Release payment safely once the work is approved.'],
                        ];
                        foreach (\$company_steps as \$step):
                        ?>
                        <div class="flex gap-5 relative z-10">
                            <div class="w-10 h-10 rounded-full bg-accent-50 dark:bg-accent-900/40 border-4 border-white dark:border-slate-800 text-accent-600 dark:text-accent-400 flex-shrink-0 flex items-center justify-center font-extrabold text-sm shadow-sm">
                                <?= \$step['num'] ?>
                            </div>
                            <div class="pt-1.5">
                                <h4 class="font-bold text-gray-900 dark:text-white text-lg mb-1.5"><?= \$step['title'] ?></h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed"><?= \$step['desc'] ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

HTML;

    $newContent = $before . $howItWorks . "\n" . $after;
    file_put_contents($file, $newContent);
    echo "Replaced successfully!\n";
} else {
    echo "Markers not found!\n";
}
