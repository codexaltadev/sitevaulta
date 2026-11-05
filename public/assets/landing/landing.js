/* ==========================================================================
   SiteVaulta — Interactive Landing Page Script
   - Interactive animated backup pipeline simulation
   - Interactive Live Pre-Backup Storage Forecaster
   - Dynamic Currency (BDT/USD) & Cycle (Monthly/Yearly) Pricing Engine
   - Terminal command interactive switcher
   - Mobile navigation toggle & header scroll observer
   - Scroll-reveal animations with reduced-motion support
   ========================================================================== */

(function () {
    'use strict';

    // Mark JS enabled for smooth CSS reveals
    document.documentElement.classList.add('js');

    // -------------------------------------------------------------------------
    // 1. Mobile Navigation & Sticky Header Observer
    // -------------------------------------------------------------------------
    const header = document.querySelector('.site-header');
    const burger = document.querySelector('.nav-burger');
    const navLinks = document.querySelectorAll('.nav-links a');

    if (burger) {
        burger.addEventListener('click', function () {
            const isOpen = document.body.classList.toggle('nav-open');
            burger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                document.body.classList.remove('nav-open');
                burger.setAttribute('aria-expanded', 'false');
            });
        });
    }

    if (header) {
        const handleScroll = () => {
            if (window.scrollY > 20) {
                header.classList.add('is-scrolled');
            } else {
                header.classList.remove('is-scrolled');
            }
        };
        window.addEventListener('scroll', handleScroll, { passive: true });
        handleScroll();
    }

    // -------------------------------------------------------------------------
    // 2. Interactive Scroll Reveal (Intersection Observer)
    // -------------------------------------------------------------------------
    if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const revealObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObserver.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
        );

        document.querySelectorAll('[data-reveal]').forEach((el) => {
            revealObserver.observe(el);
        });
    } else {
        document.querySelectorAll('[data-reveal]').forEach((el) => {
            el.classList.add('is-visible');
        });
    }

    // -------------------------------------------------------------------------
    // 3. Animated Backup Pipeline Live Simulation
    // -------------------------------------------------------------------------
    const pipelineSteps = [
        {
            stage: 'Domain & SSRF Guard',
            detail: 'DNS TXT token validated. Private subnet RFC1918 blocked.',
            pct: 18,
            activeLed: 1,
            dest: 'Origin Verified'
        },
        {
            stage: 'Dumping DB & Web Root',
            detail: 'mysqldump & wp-content stream opened via SFTP tunnel.',
            pct: 42,
            activeLed: 1,
            dest: 'Staging to /tmp'
        },
        {
            stage: 'AES-256-GCM Encryption',
            detail: 'Chunk-level encryption running. Zero unencrypted plaintext on disk.',
            pct: 68,
            activeLed: 2,
            dest: 'Encrypted Stream'
        },
        {
            stage: 'Uploading to Cloud Storage',
            detail: 'Multi-part streaming to Google Drive & AWS S3 bucket.',
            pct: 88,
            activeLed: 3,
            dest: 'BYOS Destination'
        },
        {
            stage: 'SHA-256 Integrity Verified',
            detail: 'Checksum matching. Ephemeral /tmp files safely purged.',
            pct: 100,
            activeLed: 3,
            dest: 'Backup Complete'
        }
    ];

    let currentStepIdx = 0;
    const statusLabel = document.querySelector('.status-label');
    const statusMeta = document.querySelector('.status-meta');
    const progressFill = document.querySelector('.progress > i');
    const statusPct = document.querySelector('.status-pct');

    function updatePipeline() {
        if (!statusLabel || !statusMeta || !progressFill || !statusPct) return;
        const current = pipelineSteps[currentStepIdx];

        statusLabel.textContent = current.stage;
        statusMeta.textContent = current.detail;
        progressFill.style.width = current.pct + '%';
        statusPct.textContent = current.pct + '%';

        currentStepIdx = (currentStepIdx + 1) % pipelineSteps.length;
    }

    if (statusLabel) {
        updatePipeline();
        setInterval(updatePipeline, 3200);
    }

    // -------------------------------------------------------------------------
    // 4. Live Interactive Pre-Backup Storage Forecaster
    // -------------------------------------------------------------------------
    const sizeSlider = document.getElementById('fc-size');
    const sizeOutput = document.getElementById('fc-size-out');
    const retentionSlider = document.getElementById('fc-retention');
    const retentionOutput = document.getElementById('fc-retention-out');
    const strategyButtons = document.querySelectorAll('.seg-strategy button');

    const resultPeak = document.getElementById('fc-result-peak');
    const barFull = document.getElementById('fc-bar-full');
    const barFullText = document.getElementById('fc-bar-full-text');
    const barSmart = document.getElementById('fc-bar-smart');
    const barSmartText = document.getElementById('fc-bar-smart-text');
    const savingPercent = document.getElementById('fc-saving-pct');

    let currentStrategy = 'incremental'; // 'incremental' | 'full'

    function updateSliderFill(slider) {
        if (!slider) return;
        const min = parseFloat(slider.min) || 0;
        const max = parseFloat(slider.max) || 100;
        const val = parseFloat(slider.value) || 0;
        const pct = ((val - min) / (max - min)) * 100;
        slider.style.setProperty('--fill', pct + '%');
    }

    function calculateForecast() {
        if (!sizeSlider || !retentionSlider) return;

        const rawSizeGb = parseFloat(sizeSlider.value);
        const retentionDays = parseInt(retentionSlider.value, 10);

        if (sizeOutput) sizeOutput.textContent = rawSizeGb >= 1000 ? (rawSizeGb / 1000).toFixed(1) + ' TB' : rawSizeGb + ' GB';
        if (retentionOutput) retentionOutput.textContent = retentionDays + ' Days';

        updateSliderFill(sizeSlider);
        updateSliderFill(retentionSlider);

        // Assumptions based on SiteVaulta StorageForecastService:
        // Average compression ratio: ~60% of original size (compressed factor 0.6)
        // Daily delta change rate: 4% of original size (compressed delta: 0.6 * 0.04 = 2.4%)
        const compressedBase = rawSizeGb * 0.6;
        const dailyDelta = compressedBase * 0.05;

        // Naive approach (daily full backups retained)
        const naiveTotalGb = compressedBase * retentionDays;

        let smartTotalGb = 0;
        if (currentStrategy === 'incremental') {
            // Weekly full + daily incremental deltas
            const fullCycles = Math.ceil(retentionDays / 7);
            const incrementals = Math.max(0, retentionDays - fullCycles);
            smartTotalGb = (compressedBase * fullCycles) + (dailyDelta * incrementals);
        } else {
            // Standalone Full only
            smartTotalGb = naiveTotalGb;
        }

        // Calculate saved storage %
        const savedPct = naiveTotalGb > 0 ? Math.round(((naiveTotalGb - smartTotalGb) / naiveTotalGb) * 100) : 0;

        // Format helper
        const formatGb = (gb) => {
            if (gb >= 1024) return (gb / 1024).toFixed(2) + ' TB';
            return gb.toFixed(1) + ' GB';
        };

        if (resultPeak) resultPeak.textContent = formatGb(smartTotalGb);
        if (barFullText) barFullText.textContent = formatGb(naiveTotalGb);
        if (barSmartText) barSmartText.textContent = formatGb(smartTotalGb);

        // Relative bar width
        const maxForBar = Math.max(naiveTotalGb, 1);
        if (barFull) barFull.style.width = '100%';
        if (barSmart) {
            const widthPct = Math.max(8, Math.min(100, (smartTotalGb / maxForBar) * 100));
            barSmart.style.width = widthPct + '%';
        }

        if (savingPercent) {
            savingPercent.textContent = savedPct > 0 ? `Save ${savedPct}% Storage & Cloud Bandwidth` : '100% Standalone Full Backups';
        }
    }

    if (sizeSlider && retentionSlider) {
        sizeSlider.addEventListener('input', calculateForecast);
        retentionSlider.addEventListener('input', calculateForecast);

        strategyButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                strategyButtons.forEach(b => b.setAttribute('aria-pressed', 'false'));
                btn.setAttribute('aria-pressed', 'true');
                currentStrategy = btn.getAttribute('data-strategy') || 'incremental';
                calculateForecast();
            });
        });

        calculateForecast();
    }

    // -------------------------------------------------------------------------
    // 5. Interactive Pricing Currency & Billing Cycle Switcher
    // -------------------------------------------------------------------------
    let currentCurrency = 'BDT'; // 'BDT' | 'USD'
    let currentCycle = 'monthly'; // 'monthly' | 'yearly'

    const currencyButtons = document.querySelectorAll('[data-currency-btn]');
    const cycleButtons = document.querySelectorAll('[data-cycle-btn]');
    const planCards = document.querySelectorAll('.plan[data-plan-id]');

    function updatePricingDisplay() {
        currencyButtons.forEach(btn => {
            btn.setAttribute('aria-pressed', btn.getAttribute('data-currency-btn') === currentCurrency ? 'true' : 'false');
        });

        cycleButtons.forEach(btn => {
            btn.setAttribute('aria-pressed', btn.getAttribute('data-cycle-btn') === currentCycle ? 'true' : 'false');
        });

        planCards.forEach(card => {
            const amountEl = card.querySelector('.price .amount');
            const perEl = card.querySelector('.price .per');
            const noteEl = card.querySelector('.price-note');

            if (!amountEl) return;

            const bdtMonthly = parseFloat(card.getAttribute('data-bdt-monthly') || '0');
            const bdtYearly = parseFloat(card.getAttribute('data-bdt-yearly') || '0');
            const usdMonthly = parseFloat(card.getAttribute('data-usd-monthly') || '0');
            const usdYearly = parseFloat(card.getAttribute('data-usd-yearly') || '0');
            const discountPct = parseFloat(card.getAttribute('data-discount-pct') || '0');

            amountEl.classList.add('swap');

            setTimeout(() => {
                let symbol = currentCurrency === 'BDT' ? '৳' : '$';
                let priceText = '';
                let perText = currentCycle === 'yearly' ? '/ year' : '/ month';
                let noteText = '';

                if (currentCurrency === 'BDT') {
                    if (currentCycle === 'yearly') {
                        priceText = symbol + Math.round(bdtYearly).toLocaleString();
                        if (discountPct > 0) {
                            noteText = `Equivalent to ৳${Math.round(bdtYearly / 12).toLocaleString()}/mo • <span class="save">Save ${discountPct}%</span>`;
                        }
                    } else {
                        priceText = symbol + Math.round(bdtMonthly).toLocaleString();
                        if (discountPct > 0) {
                            noteText = `Or ৳${Math.round(bdtYearly).toLocaleString()}/yr with yearly billing`;
                        }
                    }
                } else {
                    if (currentCycle === 'yearly') {
                        priceText = symbol + usdYearly.toFixed(2);
                        if (discountPct > 0) {
                            noteText = `Equivalent to $${(usdYearly / 12).toFixed(2)}/mo • <span class="save">Save ${discountPct}%</span>`;
                        }
                    } else {
                        priceText = symbol + usdMonthly.toFixed(2);
                        if (discountPct > 0) {
                            noteText = `Or $${usdYearly.toFixed(2)}/yr with yearly billing`;
                        }
                    }
                }

                amountEl.innerHTML = priceText;
                if (perEl) perEl.textContent = perText;
                if (noteEl) noteEl.innerHTML = noteText;

                amountEl.classList.remove('swap');
            }, 120);
        });
    }

    currencyButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            currentCurrency = btn.getAttribute('data-currency-btn') || 'BDT';
            updatePricingDisplay();
        });
    });

    cycleButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            currentCycle = btn.getAttribute('data-cycle-btn') || 'monthly';
            updatePricingDisplay();
        });
    });

    if (planCards.length > 0) {
        updatePricingDisplay();
    }

    // -------------------------------------------------------------------------
    // 6. Interactive Terminal Command Switcher
    // -------------------------------------------------------------------------
    const terminalTabs = document.querySelectorAll('[data-term-tab]');
    const terminalCode = document.getElementById('terminal-content');

    const commandSnippets = {
        restore: `<span class="t-comment"># 1. Download atomic one-time encrypted archive</span>
<span class="t-prompt">$</span> curl -sSL "https://sitevaulta.app/download/one-time/sv_token_9f82d1c" -o backup.enc.zip
<span class="t-comment"># 2. Verify SHA-256 integrity hash before extraction</span>
<span class="t-prompt">$</span> sha256sum -c backup.enc.zip.sha256
<span class="t-ok">backup.enc.zip: OK (Checksum Matches e3b0c44298fc1c149afbf4c8996fb92427ae41e4649)</span>
<span class="t-comment"># 3. Decrypt AES-256-GCM &amp; restore database in single command</span>
<span class="t-prompt">$</span> unzip -p backup.enc.zip database/db-dump.sql.gz | gunzip | mysql -u user -p db_name
<span class="t-ok">✓ 142 tables &amp; 3.8 GB web root restored successfully in 42 seconds!</span><span class="cursor"></span>`,

        webhook: `<span class="t-comment"># GitHub Actions / GitLab CI Pre-Deployment Backup Webhook</span>
<span class="t-prompt">$</span> curl -X POST "https://sitevaulta.app/api/v1/websites/ws_98ad2f/trigger-backup" \\
    -H "X-Webhook-Token: sv_sec_live_7718af291b84" \\
    -H "Content-Type: application/json" \\
    -d '{"sync": true, "reason": "Pre-Deploy Git Release v2.4.0"}'

<span class="t-info">{
  "status": "completed",
  "run_id": "RUN-2026-10-8849",
  "scope": "full",
  "duration_seconds": 18,
  "checksum_sha256": "4b227777d4dd1fc61c6f884f48641d02b4d121d3fd328cb08b5531fcacdabf8a"
}</span>
<span class="t-ok">✓ Safe to proceed with production deployment!</span><span class="cursor"></span>`,

        verify: `<span class="t-comment"># Mandatory Domain Ownership Verification (Anti-Scraping / SSRF Protection)</span>
<span class="t-prompt">$</span> dig +short TXT _sitevaulta-verify.mywebsite.com
<span class="t-info">"sitevaulta-verification=sv-domain-token-a99f182c401"</span>

<span class="t-comment"># Verify server connector &amp; database permissions</span>
<span class="t-prompt">$</span> php artisan sitevaulta:verify-connector --website=mywebsite.com
<span class="t-ok">✓ SFTP Connection: Latency 14ms (SSH-2.0-OpenSSH_9.6)</span>
<span class="t-ok">✓ MySQL Connection: 8.0.36-28 • LOCK TABLES, SELECT, TRIGGER Granted</span>
<span class="t-ok">✓ Storage Destination: Google Drive Folder "sitevaulta-backups" Write-OK</span><span class="cursor"></span>`
    };

    terminalTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            terminalTabs.forEach(t => t.setAttribute('aria-pressed', 'false'));
            tab.setAttribute('aria-pressed', 'true');
            const target = tab.getAttribute('data-term-tab');
            if (terminalCode && commandSnippets[target]) {
                terminalCode.innerHTML = commandSnippets[target];
            }
        });
    });

    // -------------------------------------------------------------------------
    // 7. Interactive Mouse Glow Effect on Bento Feature Cards
    // -------------------------------------------------------------------------
    const features = document.querySelectorAll('.feature');
    features.forEach(card => {
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            card.style.setProperty('--mx', x + 'px');
            card.style.setProperty('--my', y + 'px');
        });
    });

})();

