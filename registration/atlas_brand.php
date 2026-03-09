<?php
if (defined('ATLAS_BRAND_RENDERED')) {
    return;
}
define('ATLAS_BRAND_RENDERED', true);

$atlas_page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$atlas_live_bg_enabled = !in_array($atlas_page, ['login.php', 'register.php'], true);
?>
<style>
    .atlas-live-bg {
        position: fixed;
        inset: 0;
        z-index: 1;
        overflow: hidden;
        pointer-events: none;
    }

    .atlas-live-bg::before,
    .atlas-live-bg::after {
        content: "";
        position: absolute;
        inset: -20%;
    }

    .atlas-live-bg::before {
        background:
            radial-gradient(circle at 20% 22%, rgba(255, 255, 255, 0.3) 0 2px, transparent 3px),
            radial-gradient(circle at 70% 30%, rgba(255, 255, 255, 0.24) 0 2px, transparent 3px),
            radial-gradient(circle at 34% 72%, rgba(255, 255, 255, 0.28) 0 2px, transparent 3px),
            radial-gradient(circle at 82% 68%, rgba(255, 255, 255, 0.24) 0 2px, transparent 3px),
            radial-gradient(circle at 56% 52%, rgba(255, 255, 255, 0.3) 0 2px, transparent 3px),
            radial-gradient(circle at 12% 80%, rgba(255, 255, 255, 0.25) 0 2px, transparent 3px);
        opacity: 0.45;
        animation: atlasStarsDrift 26s linear infinite;
    }

    .atlas-live-bg::after {
        background:
            repeating-linear-gradient(80deg, rgba(255, 255, 255, 0.08) 0 1px, transparent 1px 140px),
            repeating-linear-gradient(12deg, rgba(79, 180, 197, 0.15) 0 1px, transparent 1px 110px);
        transform: scale(1.15);
        opacity: 0.22;
        animation: atlasMapShift 18s ease-in-out infinite alternate;
    }

    .atlas-orbit {
        position: absolute;
        width: min(60vw, 680px);
        aspect-ratio: 1 / 1;
        right: -18vw;
        bottom: -22vw;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        animation: atlasOrbit 16s linear infinite;
    }

    .atlas-orbit::before,
    .atlas-orbit::after {
        content: "";
        position: absolute;
        border-radius: 50%;
    }

    .atlas-orbit::before {
        inset: 14%;
        border: 1px dashed rgba(255, 255, 255, 0.16);
    }

    .atlas-orbit::after {
        width: 10px;
        height: 10px;
        top: 2%;
        left: 48%;
        background: rgba(255, 255, 255, 0.8);
        box-shadow: 0 0 18px rgba(255, 255, 255, 0.7);
    }

    .atlas-live-enabled > *:not(.atlas-live-bg):not(.atlas-intro-overlay):not(.atlas-logo) {
        position: relative;
        z-index: 2;
    }

    .atlas-live-enabled .container,
    .atlas-live-enabled .stat-card,
    .atlas-live-enabled .scanner-panel,
    .atlas-live-enabled .report-filters,
    .atlas-live-enabled .scan-log,
    .atlas-live-enabled table {
        background: linear-gradient(
            160deg,
            rgba(233, 245, 255, 0.85) 0%,
            rgba(216, 236, 255, 0.74) 100%
        );
        border-color: rgba(255, 255, 255, 0.4);
        backdrop-filter: blur(9px) saturate(1.1);
        box-shadow: 0 12px 26px rgba(15, 52, 72, 0.18);
        position: relative;
        overflow: hidden;
    }

    .atlas-live-enabled .container::before,
    .atlas-live-enabled .stat-card::before,
    .atlas-live-enabled .scanner-panel::before,
    .atlas-live-enabled .report-filters::before,
    .atlas-live-enabled .scan-log::before {
        content: "";
        position: absolute;
        inset: -30%;
        background:
            radial-gradient(circle at 18% 20%, rgba(31, 122, 140, 0.22) 0%, transparent 38%),
            radial-gradient(circle at 84% 22%, rgba(30, 86, 217, 0.22) 0%, transparent 38%),
            radial-gradient(circle at 52% 80%, rgba(79, 180, 197, 0.2) 0%, transparent 42%);
        animation: atlasCardFlow 14s ease-in-out infinite alternate;
        pointer-events: none;
    }

    .atlas-live-enabled .container::after,
    .atlas-live-enabled .stat-card::after,
    .atlas-live-enabled .scanner-panel::after,
    .atlas-live-enabled .report-filters::after,
    .atlas-live-enabled .scan-log::after {
        content: "";
        position: absolute;
        inset: 0;
        background:
            repeating-linear-gradient(
                100deg,
                rgba(255, 255, 255, 0.18) 0 1px,
                transparent 1px 14px
            );
        opacity: 0.25;
        animation: atlasCardLines 9s linear infinite;
        pointer-events: none;
    }

    .atlas-live-enabled .container > *,
    .atlas-live-enabled .stat-card > *,
    .atlas-live-enabled .scanner-panel > *,
    .atlas-live-enabled .report-filters > *,
    .atlas-live-enabled .scan-log > * {
        position: relative;
        z-index: 1;
    }

    .atlas-live-enabled .role-option span {
        background: rgba(255, 255, 255, 0.72);
    }

    .atlas-intro-overlay {
        position: fixed;
        inset: 0;
        z-index: 9998;
        background:
            radial-gradient(700px 380px at 10% 10%, rgba(79, 180, 197, 0.35) 0%, transparent 70%),
            radial-gradient(680px 360px at 88% 12%, rgba(241, 191, 160, 0.35) 0%, transparent 70%),
            linear-gradient(170deg, #1f7a8c 0%, #2f8998 55%, #4e9fa8 100%);
        transition: opacity 0.45s ease, visibility 0.45s ease;
        pointer-events: none;
    }

    .atlas-intro-overlay.hidden {
        opacity: 0;
        visibility: hidden;
    }

    .atlas-logo {
        position: fixed;
        top: 18px;
        left: 18px;
        z-index: 9999;
        display: inline-flex;
        gap: 0.12em;
        font-size: clamp(1.05rem, 2.5vw, 1.35rem);
        font-weight: 800;
        letter-spacing: 0.18em;
        color: #ffffff;
        text-shadow: 0 8px 24px rgba(0, 0, 0, 0.24);
        pointer-events: none;
        user-select: none;
    }

    .atlas-live-enabled .atlas-logo {
        color: #ffffff;
        text-shadow: 0 8px 24px rgba(0, 0, 0, 0.24);
    }

    .atlas-live-enabled .atlas-logo-fly {
        animation:
            atlasFlyToCorner 1.45s cubic-bezier(0.2, 0.7, 0.2, 1) forwards,
            atlasLogoToBlue 1.45s linear forwards;
    }

    .atlas-logo-fly {
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(2);
        animation: atlasFlyToCorner 1.45s cubic-bezier(0.2, 0.7, 0.2, 1) forwards;
    }

    .atlas-letter {
        display: inline-block;
        opacity: 0;
        transform: translateY(14px) scale(0.92);
        animation: atlasLetterIn 0.5s ease forwards;
        animation-delay: calc(var(--index) * 0.12s);
    }

    @keyframes atlasFlyToCorner {
        0% {
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(2);
        }
        58% {
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(2);
        }
        100% {
            top: 18px;
            left: 18px;
            transform: translate(0, 0) scale(1);
        }
    }

    @keyframes atlasLetterIn {
        0% {
            opacity: 0;
            transform: translateY(14px) scale(0.92);
        }
        100% {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    @keyframes atlasLogoToBlue {
        0%,
        52% {
            color: #ffffff;
            text-shadow: 0 8px 24px rgba(0, 0, 0, 0.24);
        }
        100% {
            color: #1e56d9;
            text-shadow: 0 8px 20px rgba(16, 38, 97, 0.3);
        }
    }

    @keyframes atlasStarsDrift {
        from {
            transform: translate3d(0, 0, 0);
        }
        to {
            transform: translate3d(-5%, -8%, 0);
        }
    }

    @keyframes atlasMapShift {
        from {
            transform: scale(1.15) translate3d(0, 0, 0);
        }
        to {
            transform: scale(1.22) translate3d(-2%, 3%, 0);
        }
    }

    @keyframes atlasOrbit {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }

    @keyframes atlasCardFlow {
        0% {
            transform: translate3d(-2%, -2%, 0) scale(1.02);
            filter: hue-rotate(0deg) saturate(1);
        }
        50% {
            transform: translate3d(2%, 2%, 0) scale(1.05);
            filter: hue-rotate(18deg) saturate(1.08);
        }
        100% {
            transform: translate3d(0, 1%, 0) scale(1.03);
            filter: hue-rotate(30deg) saturate(1.06);
        }
    }

    @keyframes atlasCardLines {
        from {
            background-position: 0 0;
        }
        to {
            background-position: 160px 0;
        }
    }

    @media (max-width: 576px) {
        .atlas-logo {
            top: 10px;
            left: 10px;
        }

        @keyframes atlasFlyToCorner {
            0% {
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) scale(1.7);
            }
            58% {
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) scale(1.7);
            }
            100% {
                top: 10px;
                left: 10px;
                transform: translate(0, 0) scale(1);
            }
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .atlas-live-bg::before,
        .atlas-live-bg::after,
        .atlas-orbit,
        .atlas-live-enabled .container::before,
        .atlas-live-enabled .stat-card::before,
        .atlas-live-enabled .scanner-panel::before,
        .atlas-live-enabled .report-filters::before,
        .atlas-live-enabled .scan-log::before,
        .atlas-live-enabled .container::after,
        .atlas-live-enabled .stat-card::after,
        .atlas-live-enabled .scanner-panel::after,
        .atlas-live-enabled .report-filters::after,
        .atlas-live-enabled .scan-log::after {
            animation: none;
        }

        .atlas-letter {
            animation: none;
            opacity: 1;
            transform: none;
        }

        .atlas-intro-overlay {
            transition: none;
        }

        .atlas-logo-fly {
            top: 18px;
            left: 18px;
            transform: translate(0, 0) scale(1);
            animation: none;
        }
    }
</style>

<?php if ($atlas_live_bg_enabled): ?>
<script>
    document.body.classList.add('atlas-live-enabled');
</script>
<div class="atlas-live-bg" aria-hidden="true">
    <span class="atlas-orbit"></span>
</div>
<?php endif; ?>

<div id="atlasIntro" class="atlas-intro-overlay" aria-hidden="true"></div>
<div id="atlasLogo" class="atlas-logo atlas-logo-fly" aria-label="ATLAS">
    <span class="atlas-letter" style="--index:0;">A</span>
    <span class="atlas-letter" style="--index:1;">T</span>
    <span class="atlas-letter" style="--index:2;">L</span>
    <span class="atlas-letter" style="--index:3;">A</span>
    <span class="atlas-letter" style="--index:4;">S</span>
</div>

<script>
    (function () {
        if (window.__atlasBrandInitialized) {
            return;
        }
        window.__atlasBrandInitialized = true;

        const intro = document.getElementById('atlasIntro');
        if (!intro) {
            return;
        }

        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const introDurationMs = reduceMotion ? 120 : 1450;

        window.setTimeout(function () {
            intro.classList.add('hidden');
            window.setTimeout(function () {
                if (intro.parentNode) {
                    intro.parentNode.removeChild(intro);
                }
            }, reduceMotion ? 0 : 500);
        }, introDurationMs);
    })();
</script>
