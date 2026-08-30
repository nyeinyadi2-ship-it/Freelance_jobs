<?php
                    $ms_asgn = null;
                    foreach($assignments as $a) {
                        if($a['freelancer_id'] == $ms['freelancer_id']) {
                            $ms_asgn = $a;
                            break;
                        }
                    }

                    $ms_labels = [
                        'draft'=>'Draft','funded'=>'Funded','in_progress'=>'In Progress',
                        'submitted'=>'Submitted','approved'=>'Approved','completed'=>'Completed','paid'=>'Paid','payment_pending'=>'Payment Pending',
                        'pending'=>'Pending','revision_requested'=>'Revision Requested',
                        'overdue'=>'Overdue','cancelled'=>'Cancelled','rejected'=>'Rejected'
                    ];
                    $ms_colors = [
                        'draft'=>'#6b7280','funded'=>'#3b82f6','in_progress'=>'#6366f1',
                        'submitted'=>'#8b5cf6','approved'=>'#10b981','completed'=>'#10b981','paid'=>'#10b981','payment_pending'=>'#f59e0b',
                        'pending'=>'#f59e0b','revision_requested'=>'#f97316',
                        'overdue'=>'#dc2626','cancelled'=>'#6b7280','rejected'=>'#ef4444'
                    ];
                    $ms_label = $ms_labels[$ms['status']] ?? $ms['status'];
                    $ms_color = $ms_colors[$ms['status']] ?? '#6b7280';

                    $tz = new DateTimeZone('Asia/Yangon');
                    $now_yangon = new DateTime('now', $tz);
                ?>
                <div class="p-4 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border);<?= $ms['status'] === 'overdue' ? 'border-color:rgba(220,38,38,0.3);' : '' ?><?= $ms['status'] === 'cancelled' ? 'opacity:0.6;' : '' ?>">
                    <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2">
                            <?php if ($ms['status'] === 'approved'): ?>
                                <div class="w-6 h-6 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </div>
                            <?php elseif ($ms['status'] === 'overdue'): ?>
                                <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0" style="background:rgba(220,38,38,0.1)">
                                    <svg class="w-3.5 h-3.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                </div>
                            <?php elseif ($ms['status'] === 'cancelled'): ?>
                                <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0" style="background:rgba(107,114,128,0.1)">
                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                </div>
                            <?php else: ?>
                                <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold" style="background:var(--color-card);border:1.5px solid var(--color-border);color:var(--color-text-muted)"><?= $ms['sort_order'] ?></div>
                            <?php endif; ?>
                            <div>
                                <p class="text-sm font-bold" style="color:var(--color-text-primary)"><?= e($ms['title']) ?></p>
                                <?php if ($ms['description']): ?><p class="text-xs" style="color:var(--color-text-muted)"><?= e($ms['description']) ?></p><?php endif; ?>
                                <p class="text-[11px] mt-0.5" style="color:var(--color-text-muted)">
                                    <?php if (!empty($ms['deadline'])): ?>
                                        Deadline: <?= date('M j, Y g:ia', strtotime($ms['deadline'])) ?>
                                        <?php if ($ms['status'] === 'overdue'): ?>
                                            <span class="text-red-500 font-semibold">(Overdue)</span>
                                        <?php endif; ?>
                                        &middot;
                                    <?php endif; ?>
                                    Assigned to: <strong><?= e($ms['assigned_freelancer_name'] ?? 'Unassigned') ?></strong>
                                </p>
                                <?php if ($ms['status'] === 'cancelled' && !empty($ms['rejection_reason'])): ?>
                                    <p class="text-[11px] mt-1 text-red-500">Reason: <?= e($ms['rejection_reason']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold" style="color:#f59e0b"><?= number_format((float) $ms['amount'], 2) ?> MMK</span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold" style="background:<?= $ms_color ?>15;color:<?= $ms_color ?>"><?= $ms_label ?></span>
                        </div>
                    </div>

                    <?php if ($ms['status'] === 'submitted'): ?>
                        <!-- Submission Preview (compact) -->
                        <div class="mt-2 p-3 rounded-lg" style="background:rgba(139,92,246,0.04);border:1px solid rgba(139,92,246,0.12)">
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></div>
                                    <span class="text-xs font-semibold text-purple-600 dark:text-purple-400">Work Submitted</span>
                                </div>
                                <?php if ($ms['submitted_at']): ?>
                                    <span class="text-[11px]" style="color:var(--color-text-muted)"><?= date('M j, Y g:ia', strtotime($ms['submitted_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Submission Detail Modal -->
                        <div id="submissionModal-<?= $ms['id'] ?>" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
                            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('submissionModal-<?= $ms['id'] ?>').classList.add('hidden')"></div>
                            <div class="relative w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden" style="background:var(--color-card);border:1px solid var(--color-border);max-height:85vh;display:flex;flex-direction:column">
                                <div class="flex items-center justify-between p-5 border-b" style="border-color:var(--color-border)">
                                    <div>
                                        <h3 class="text-base font-bold" style="color:var(--color-text-primary)">Submission Details</h3>
                                        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Milestone: <?= e($ms['title']) ?></p>
                                    </div>
                                    <button type="button" onclick="document.getElementById('submissionModal-<?= $ms['id'] ?>').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                        <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                <div class="p-5 space-y-4 overflow-y-auto flex-1">
                                    <?php if ($ms['submitted_at']): ?>
                                    <div class="flex items-center gap-2.5 p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(139,92,246,0.1)">
                                            <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        </div>
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Submitted</p>
                                            <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= date('F j, Y \a\t g:ia', strtotime($ms['submitted_at'])) ?></p>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($ms['submission_file'])):
                                        $file_ext = strtolower(pathinfo($ms['submission_file'], PATHINFO_EXTENSION));
                                        $file_icons = ['pdf'=>'text-red-500','doc'=>'text-blue-500','docx'=>'text-blue-600','zip'=>'text-yellow-600','rar'=>'text-purple-600','jpg'=>'text-green-500','jpeg'=>'text-green-500','png'=>'text-green-500','gif'=>'text-green-500','webp'=>'text-green-500'];
                                        $file_color = $file_icons[$file_ext] ?? 'text-gray-500';
                                    ?>
                                    <div class="p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                        <div class="flex items-center gap-2 mb-2">
                                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                            <span class="text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Attached File</span>
                                        </div>
                                        <div class="flex items-center gap-3 p-3 rounded-xl" style="background:var(--color-card);border:1px solid var(--color-border)">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(16,185,129,0.1)">
                                                <svg class="w-5 h-5 <?= $file_color ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e(basename($ms['submission_file'])) ?></p>
                                                <p class="text-[11px] uppercase font-semibold" style="color:var(--color-text-muted)"><?= e($file_ext) ?> file</p>
                                            </div>
                                            <a href="<?= e(base_url('api/download_submission.php?milestone_id=' . $ms['id'])) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all" style="background:linear-gradient(135deg,#10b981,#059669)">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                Download
                                            </a>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($ms['submission_note'])): ?>
                                    <div class="p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                        <div class="flex items-center gap-2 mb-2">
                                            <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
                                            <span class="text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Freelancer's Note</span>
                                        </div>
                                        <div class="p-3 rounded-lg text-sm leading-relaxed whitespace-pre-wrap" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-secondary)"><?= nl2br(e($ms['submission_note'])) ?></div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (empty($ms['submission_file']) && empty($ms['submission_note'])): ?>
                                        <div class="text-center py-6" style="color:var(--color-text-muted)">
                                            <p class="text-xs">No submission details provided.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="p-5 border-t flex flex-col gap-3" style="border-color:var(--color-border)">
                                    <div class="w-full">
                                        <a href="<?= e(base_url('company/pay_freelancer.php?milestone_id=' . $ms['id'])) ?>" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition-all hover:-translate-y-0.5" style="background:linear-gradient(135deg,#10b981,#059669);box-shadow:0 2px 8px rgba(16,185,129,0.3)">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            Approve & Pay <?= number_format((float) $ms['amount'], 2) ?> MMK
                                        </a>
                                    </div>
                                    <form method="POST" class="w-full mt-2 border-t pt-4" style="border-color:var(--color-border)">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                        <input type="hidden" name="ms_action" value="revision">
                                        <input type="hidden" name="milestone_id" value="<?= (int) $ms['id'] ?>">
                                        <label class="block text-sm font-medium mb-2 text-red-600">Request Revision</label>
                                        <textarea name="revision_notes" required placeholder="Provide clear instructions on what needs to be revised..." class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-red-500 mb-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white" rows="2"></textarea>
                                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold transition-all text-red-600 border border-red-200 hover:bg-red-50 dark:hover:bg-red-900/30" onclick="return confirm('Request revision for this milestone?')">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            Request Revision
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action buttons per milestone -->
                    <div class="mt-3 flex flex-wrap gap-2">
                        <?php if ($ms['status'] === 'draft' && $ms['freelancer_id']): ?>
                            <form method="POST" class="w-full">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="ms_action" value="start_milestone">
                                <input type="hidden" name="milestone_id" value="<?= (int) $ms['id'] ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all hover:-translate-y-0.5" style="background:linear-gradient(135deg,#6366f1,#4f46e5);box-shadow:0 2px 8px rgba(99,102,241,0.3)" onclick="return confirm('Start this milestone? No funds will be deducted until you approve the submitted work.')">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Start Milestone
                                </button>
                            </form>
                        <?php elseif ($ms['status'] === 'in_progress'): ?>
                            <div class="flex items-center justify-between w-full">
                                <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#6366f1">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Freelancer working — awaiting submission
                                </span>
                                <button type="button" onclick="document.getElementById('extendMsModal-<?= $ms['id'] ?>').classList.remove('hidden')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all bg-blue-500 hover:bg-blue-600">
                                    Extend Deadline
                                </button>
                            </div>
                        <?php elseif ($ms['status'] === 'funded'): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#3b82f6">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Funded — Awaiting submission
                            </span>
                        <?php elseif ($ms['status'] === 'submitted'): ?>
                            <button type="button" onclick="document.getElementById('submissionModal-<?= $ms['id'] ?>').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);box-shadow:0 2px 8px rgba(139,92,246,0.3)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View Submission & Review
                            </button>
                        <?php elseif (in_array($ms['status'], ['approved', 'payment_pending'])): ?>
                            <a href="<?= e(base_url('company/pay_freelancer.php?milestone_id=' . $ms['id'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all hover:-translate-y-0.5" style="background:linear-gradient(135deg,#10b981,#059669);box-shadow:0 2px 8px rgba(16,185,129,0.3)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                Pay / Release Milestone
                            </a>
                        <?php elseif ($ms['status'] === 'paid'): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#10b981">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Paid
                            </span>
                        <?php elseif ($ms['status'] === 'revision_requested'): ?>
                            <div class="flex items-center justify-between w-full">
                                <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#ef4444">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    Awaiting resubmission
                                </span>
                                <button type="button" onclick="document.getElementById('extendMsModal-<?= $ms['id'] ?>').classList.remove('hidden')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all bg-blue-500 hover:bg-blue-600">
                                    Extend Deadline
                                </button>
                            </div>
                        <?php elseif ($ms['status'] === 'overdue'): ?>
                            <?php
                            $ms_ext_req    = (int)($ms['extension_requested'] ?? 0);
                            $ms_ext_status = $ms['extension_status'] ?? 'none';
                            ?>
                            <?php if ($ms_ext_req === 1 && $ms_ext_status === 'pending'): ?>
                                <!-- Freelancer has a pending extension request -->
                                <div class="flex items-center justify-between w-full">
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-amber-600">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Extension Requested
                                    </span>
                                    <div class="flex gap-1.5">
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="ms_action" value="approve_extension">
                                            <input type="hidden" name="milestone_id" value="<?= $ms['id'] ?>">
                                            <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                            <button type="button" onclick="document.getElementById('approveExtModal-<?= $ms['id'] ?>').classList.remove('hidden')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-emerald-500 hover:bg-emerald-600 transition-all">
                                                Approve Extension
                                            </button>
                                        </form>
                                        <button type="button" onclick="document.getElementById('rejectExtModal-<?= $ms['id'] ?>').classList.remove('hidden')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-red-500 hover:bg-red-600 transition-all">
                                            Reject Extension
                                        </button>
                                    </div>
                                </div>
                            <?php elseif ($ms_ext_req === 1 && $ms_ext_status === 'approved'): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Extension Approved
                                </span>
                            <?php elseif ($ms_ext_req === 1 && $ms_ext_status === 'rejected'): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-red-500">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Extension Rejected
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-red-500">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Overdue — awaiting freelancer action
                                </span>
                            <?php endif; ?>
                        <?php elseif ($ms['status'] === 'cancelled'): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#6b7280">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                Cancelled
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Extend Milestone Deadline Modal -->
                    <div id="extendMsModal-<?= $ms['id'] ?>" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
                        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('extendMsModal-<?= $ms['id'] ?>').classList.add('hidden')"></div>
                        <div class="relative w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="background:var(--color-card);border:1px solid var(--color-border)">
                            <div class="flex items-center justify-between p-5 border-b" style="border-color:var(--color-border)">
                                <h3 class="text-base font-bold" style="color:var(--color-text-primary)">Extend Milestone Deadline</h3>
                                <button type="button" onclick="document.getElementById('extendMsModal-<?= $ms['id'] ?>').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                    <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="p-5">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="ms_action" value="extend_ms_deadline">
                                    <input type="hidden" name="milestone_id" value="<?= $ms['id'] ?>">
                                    
                                    <div class="mb-4 text-sm text-gray-700 dark:text-gray-300">
                                        <p class="mb-1"><strong>Milestone:</strong> <?= e($ms['title']) ?></p>
                                        <p><strong>Current Deadline:</strong> <?= !empty($ms['deadline']) ? date('M j, Y g:ia', strtotime($ms['deadline'])) : 'Not set' ?></p>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">New Deadline <span class="text-red-500">*</span></label>
                                        <input type="datetime-local" name="new_deadline" required min="<?= date('Y-m-d\TH:i') ?>" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow">
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Extension Reason (Optional)</label>
                                        <textarea name="extension_reason" rows="2" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow" placeholder="Reason for extending the deadline..."></textarea>
                                    </div>
                                    
                                    <div class="flex gap-2 justify-end">
                                        <button type="button" onclick="document.getElementById('extendMsModal-<?= $ms['id'] ?>').classList.add('hidden')" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Cancel</button>
                                        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white rounded-lg transition-all" style="background:linear-gradient(135deg,#3b82f6,#2563eb);box-shadow:0 2px 8px rgba(59,130,246,0.3)">Confirm Extension</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Cancel/Reject Overdue Milestone Modal -->
                    <div id="cancelMsModal-<?= $ms['id'] ?>" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
                        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('cancelMsModal-<?= $ms['id'] ?>').classList.add('hidden')"></div>
                        <div class="relative w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="background:var(--color-card);border:1px solid var(--color-border)">
                            <div class="flex items-center justify-between p-5 border-b" style="border-color:var(--color-border)">
                                <h3 class="text-base font-bold text-red-600">Reject / Cancel Milestone</h3>
                                <button type="button" onclick="document.getElementById('cancelMsModal-<?= $ms['id'] ?>').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                    <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="p-5">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="ms_action" value="cancel_ms_project">
                                    <input type="hidden" name="milestone_id" value="<?= $ms['id'] ?>">

                                    <div class="mb-4 p-3 rounded-lg" style="background:rgba(239,68,68,0.05);border:1px solid rgba(239,68,68,0.15)">
                                        <p class="text-sm" style="color:var(--color-text-secondary)">This will cancel the milestone <strong>"<?= e($ms['title']) ?>"</strong> for <strong><?= e($ms['assigned_freelancer_name'] ?? 'the freelancer') ?></strong>. This action cannot be undone.</p>
                                    </div>

                                    <div class="mb-4">
                                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Reason for Cancellation <span class="text-red-500">*</span></label>
                                        <textarea name="rejection_reason" required rows="3" placeholder="Provide a reason for cancelling this milestone..." class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow"></textarea>
                                    </div>

                                    <div class="flex gap-2 justify-end">
                                        <button type="button" onclick="document.getElementById('cancelMsModal-<?= $ms['id'] ?>').classList.add('hidden')" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Cancel</button>
                                        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-all" onclick="return confirm('Are you sure you want to cancel this milestone?')">Confirm</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Extension Request Info Panel (shown when freelancer has requested extension) -->
                    <?php
                    $ms_ext_req2    = (int)($ms['extension_requested'] ?? 0);
                    $ms_ext_status2 = $ms['extension_status'] ?? 'none';
                    ?>
                    <?php if ($ms_ext_req2 === 1): ?>
                    <div class="mt-3 p-3 rounded-xl" style="background:rgba(245,158,11,0.05);border:1px solid rgba(245,158,11,0.2)">
                        <p class="text-xs font-bold uppercase tracking-wider mb-2" style="color:#b45309">Extension Request</p>
                        <div class="space-y-1 text-xs" style="color:var(--color-text-secondary)">
                            <?php if (!empty($ms['deadline'])): ?>
                            <div class="flex justify-between">
                                <span style="color:var(--color-text-muted)">Current Deadline</span>
                                <span class="font-semibold"><?= date('M j, Y', strtotime($ms['deadline'])) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($ms['extension_deadline'])): ?>
                            <div class="flex justify-between">
                                <span style="color:var(--color-text-muted)">Requested Deadline</span>
                                <span class="font-semibold text-amber-600"><?= date('M j, Y', strtotime($ms['extension_deadline'])) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($ms['extension_reason'])): ?>
                            <div class="mt-1">
                                <span style="color:var(--color-text-muted)">Reason: </span>
                                <span><?= e($ms['extension_reason']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($ms['extension_requested_at'])): ?>
                            <div class="flex justify-between">
                                <span style="color:var(--color-text-muted)">Requested</span>
                                <span><?= date('M j, Y g:ia', strtotime($ms['extension_requested_at'])) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between mt-1">
                                <span style="color:var(--color-text-muted)">Status</span>
                                <?php if ($ms_ext_status2 === 'pending'): ?>
                                    <span class="font-bold text-amber-600">Pending</span>
                                <?php elseif ($ms_ext_status2 === 'approved'): ?>
                                    <span class="font-bold text-emerald-600">Approved</span>
                                <?php elseif ($ms_ext_status2 === 'rejected'): ?>
                                    <span class="font-bold text-red-500">Rejected</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Approve Extension Modal -->
                    <?php if (($ms['extension_status'] ?? 'none') === 'pending'): ?>
                    <div id="approveExtModal-<?= $ms['id'] ?>" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
                        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('approveExtModal-<?= $ms['id'] ?>').classList.add('hidden')"></div>
                        <div class="relative w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="background:var(--color-card);border:1px solid var(--color-border)">
                            <div class="flex items-center justify-between p-5 border-b" style="border-color:var(--color-border)">
                                <h3 class="text-base font-bold text-emerald-600">Approve Extension Request</h3>
                                <button type="button" onclick="document.getElementById('approveExtModal-<?= $ms['id'] ?>').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                    <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="p-5">
                                <div class="mb-4 p-3 rounded-lg space-y-1 text-sm" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                    <p><strong>Milestone:</strong> <?= e($ms['title']) ?></p>
                                    <?php if (!empty($ms['deadline'])): ?>
                                    <p><strong>Current Deadline:</strong> <?= date('M j, Y', strtotime($ms['deadline'])) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($ms['extension_deadline'])): ?>
                                    <p><strong>New Deadline:</strong> <span class="text-emerald-600 font-bold"><?= date('M j, Y', strtotime($ms['extension_deadline'])) ?></span></p>
                                    <?php endif; ?>
                                    <?php if (!empty($ms['extension_reason'])): ?>
                                    <p><strong>Reason:</strong> <?= e($ms['extension_reason']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <p class="text-sm text-emerald-600 mb-4">Approving will update the deadline and restore the milestone to <strong>In Progress</strong>.</p>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="ms_action" value="approve_extension">
                                    <input type="hidden" name="milestone_id" value="<?= $ms['id'] ?>">
                                    <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                    <div class="flex gap-2 justify-end">
                                        <button type="button" onclick="document.getElementById('approveExtModal-<?= $ms['id'] ?>').classList.add('hidden')" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Cancel</button>
                                        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white bg-emerald-500 hover:bg-emerald-600 rounded-lg transition-all" onclick="return confirm('Approve this extension request?')">Approve Extension</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Reject Extension Modal -->
                    <div id="rejectExtModal-<?= $ms['id'] ?>" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
                        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('rejectExtModal-<?= $ms['id'] ?>').classList.add('hidden')"></div>
                        <div class="relative w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="background:var(--color-card);border:1px solid var(--color-border)">
                            <div class="flex items-center justify-between p-5 border-b" style="border-color:var(--color-border)">
                                <h3 class="text-base font-bold" style="color:var(--color-text-primary)">Reject Extension Request</h3>
                                <button type="button" onclick="document.getElementById('rejectExtModal-<?= $ms['id'] ?>').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                    <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="p-5">
                                <?php if (!empty($ms['extension_reason'])): ?>
                                <div class="mb-3 p-3 rounded-lg text-sm" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                    <p><strong>Freelancer's Reason:</strong> <?= e($ms['extension_reason']) ?></p>
                                </div>
                                <?php endif; ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="ms_action" value="reject_extension">
                                    <input type="hidden" name="milestone_id" value="<?= $ms['id'] ?>">
                                    <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                    <div class="flex gap-2 justify-end">
                                        <button type="button" onclick="document.getElementById('rejectExtModal-<?= $ms['id'] ?>').classList.add('hidden')" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Cancel</button>
                                        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white bg-red-500 hover:bg-red-600 rounded-lg transition-all" onclick="return confirm('Reject this extension request?')">Reject Extension</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

