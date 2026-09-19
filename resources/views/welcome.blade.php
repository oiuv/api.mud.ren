<!doctype html>
<html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="dark">
        <meta name="theme-color" content="#0b0d10">
        <meta name="description" content="MUDREN API，MUD 玩家社区的后端接口服务。前往社区交流，或在 MUD 百科探索文字世界。">
        <title>MUDREN API · 连接文字世界</title>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='10' fill='%230b0d10'/%3E%3Cpath d='M9 28V12l11 10 11-10v16' fill='none' stroke='%23eea366' stroke-width='3' stroke-linejoin='round'/%3E%3C/svg%3E">
        <style>
            :root {
                color-scheme: dark;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
                color: #f0efec;
                background: #0b0d10;
                font-synthesis: none;
                -webkit-font-smoothing: antialiased;
            }

            * {
                box-sizing: border-box;
            }

            body {
                min-width: 280px;
                min-height: 100vh;
                min-height: 100svh;
                margin: 0;
                background:
                    radial-gradient(ellipse at 50% 37%, rgba(185, 101, 49, .11), transparent 48%),
                    radial-gradient(ellipse at 85% 85%, rgba(87, 115, 134, .06), transparent 38%);
            }

            body::before {
                position: fixed;
                z-index: -1;
                inset: 0;
                background-image:
                    linear-gradient(rgba(255, 255, 255, .025) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255, 255, 255, .025) 1px, transparent 1px);
                background-size: 64px 64px;
                mask-image: radial-gradient(ellipse at 50% 40%, #000, transparent 72%);
                content: "";
                pointer-events: none;
            }

            ::selection {
                color: #0b0d10;
                background: #eea366;
            }

            .page {
                display: flex;
                flex-direction: column;
                min-height: 100vh;
                min-height: 100svh;
                max-width: 1280px;
                margin: 0 auto;
                padding: 30px 48px 24px;
            }

            .masthead,
            .footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
            }

            .brand {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 15px;
                font-weight: 750;
                letter-spacing: .08em;
                color: inherit;
                text-decoration: none;
            }

            .brand svg {
                width: 28px;
                height: 28px;
                color: #eea366;
            }

            .brand span {
                color: #eea366;
            }

            .endpoint,
            .eyebrow,
            .domain,
            .footer-label {
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
            }

            .endpoint {
                color: #96969b;
                font-size: 12px;
                letter-spacing: .03em;
            }

            main {
                display: flex;
                flex: 1;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 48px 0 64px;
                text-align: center;
            }

            .constellation {
                position: relative;
                width: 204px;
                height: 204px;
                margin-bottom: 28px;
            }

            .constellation::before {
                position: absolute;
                inset: 30px;
                border-radius: 50%;
                background: rgba(236, 149, 86, .12);
                filter: blur(32px);
                content: "";
            }

            .orbit {
                position: absolute;
                inset: 6px;
                border: 1px solid rgba(238, 163, 102, .16);
                border-radius: 50%;
                animation: revolve 48s linear infinite;
            }

            .orbit::before,
            .orbit::after {
                position: absolute;
                top: 50%;
                width: 5px;
                height: 5px;
                border-radius: 50%;
                background: #eea366;
                box-shadow: 0 0 14px rgba(238, 163, 102, .7);
                content: "";
            }

            .orbit::before {
                left: -3px;
            }

            .orbit::after {
                right: -3px;
                background: #bcb0a5;
            }

            .orbit-inner {
                inset: 27px;
                border-style: dashed;
                border-color: rgba(238, 163, 102, .12);
                animation-direction: reverse;
                animation-duration: 64s;
            }

            .orbit-inner::before,
            .orbit-inner::after {
                width: 3px;
                height: 3px;
                box-shadow: none;
            }

            .core {
                position: absolute;
                inset: 59px;
                display: grid;
                place-items: center;
                border: 1px solid rgba(238, 163, 102, .38);
                border-radius: 24px;
                background: linear-gradient(145deg, #30251e, #141416);
                box-shadow: inset 0 1px 0 rgba(255, 223, 195, .08), 0 14px 50px rgba(0, 0, 0, .3);
                transform: rotate(-8deg);
            }

            .core svg {
                width: 44px;
                height: 44px;
                color: #f2b17d;
                transform: rotate(8deg);
            }

            .eyebrow {
                margin: 0 0 18px;
                color: #c59a78;
                font-size: 11px;
                letter-spacing: .28em;
            }

            h1 {
                margin: 0;
                font-size: clamp(40px, 8.4vw, 92px);
                font-weight: 750;
                letter-spacing: -.055em;
                line-height: 1.12;
            }

            h1 span {
                color: #eea366;
                font-weight: 400;
            }

            .description {
                margin: 24px 0 0;
                color: #aaa7a5;
                font-size: 15px;
                line-height: 1.9;
                letter-spacing: .06em;
            }

            .destinations {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
                width: 100%;
                max-width: 640px;
                margin-top: 44px;
                text-align: left;
            }

            .destination {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 23px 24px;
                border: 1px solid #303033;
                border-radius: 14px;
                color: inherit;
                background: linear-gradient(115deg, rgba(255, 255, 255, .035), rgba(255, 255, 255, .012));
                text-decoration: none;
                transition: border-color .2s, background-color .2s, transform .2s;
            }

            .destination:hover {
                border-color: #916b4d;
                background-color: rgba(238, 163, 102, .04);
                transform: translateY(-3px);
            }

            .brand:focus-visible,
            .destination:focus-visible {
                outline: 2px solid #eea366;
                outline-offset: 5px;
            }

            .destination-icon {
                flex-shrink: 0;
                width: 24px;
                height: 24px;
                color: #c4a184;
            }

            .destination-copy {
                flex: 1;
            }

            .destination-title {
                display: block;
                font-size: 14px;
                font-weight: 500;
                line-height: 1.5;
            }

            .domain {
                display: block;
                margin-top: 5px;
                color: #a09c99;
                font-size: 12px;
            }

            .arrow {
                flex-shrink: 0;
                width: 18px;
                height: 18px;
                color: #b49b86;
                transition: transform .2s;
            }

            .destination:hover .arrow {
                transform: translate(2px, -2px);
            }

            .footer {
                padding-top: 20px;
                border-top: 1px solid #242428;
                color: #969296;
                font-size: 11px;
            }

            .footer-label {
                font-size: 10px;
                letter-spacing: .12em;
            }

            @keyframes revolve {
                to {
                    transform: rotate(360deg);
                }
            }

            @media (max-width: 600px) {
                .page {
                    padding: 24px 24px 20px;
                }

                main {
                    padding: 36px 0 42px;
                }

                .constellation {
                    margin-bottom: 24px;
                }

                .description {
                    margin-top: 20px;
                    font-size: 13px;
                    letter-spacing: .02em;
                }

                .destinations {
                    grid-template-columns: 1fr;
                    gap: 12px;
                    max-width: 360px;
                    margin-top: 32px;
                }

                .destination {
                    padding: 18px 20px;
                }

                .footer {
                    flex-wrap: wrap;
                    gap: 8px 16px;
                }
            }

            @media (prefers-reduced-motion: reduce) {
                *,
                *::before,
                *::after {
                    animation: none !important;
                    transition: none !important;
                }
            }
        </style>
    </head>
    <body>
        <div class="page">
            <header class="masthead">
                <a class="brand" href="https://mud.ren/" aria-label="MUD.REN 首页">
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                        <path d="M5 24V8l11 10L27 8v16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <div>MUD<span>.REN</span></div>
                </a>
                <span class="endpoint">api.mud.ren</span>
            </header>

            <main>
                <div class="constellation" aria-hidden="true">
                    <div class="orbit"></div>
                    <div class="orbit orbit-inner"></div>
                    <div class="core">
                        <svg viewBox="0 0 48 48" fill="none">
                            <path d="m16 15-9 9 9 9m16-18 9 9-9 9M27 12l-6 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                </div>
                <p class="eyebrow">CONNECTING TEXT WORLDS</p>
                <h1>MUDREN <span>API</span></h1>
                <p class="description">MUD 玩家社区的后端接口服务<br>连接热爱，让文字世界生生不息。</p>

                <nav class="destinations" aria-label="探索 MUD 社区">
                    <a class="destination" href="https://bbs.mud.ren/">
                        <svg class="destination-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M20 11.5a8 8 0 0 1-8 8 9 9 0 0 1-3.5-.7L4 20l1.2-4.5a9 9 0 0 1-.7-3.5 8 8 0 0 1 8-8H14a6 6 0 0 1 6 6v1.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                            <path d="M8.5 10h7m-7 4h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <span class="destination-copy">
                            <span class="destination-title">玩家社区</span>
                            <span class="domain">bbs.mud.ren</span>
                        </span>
                        <svg class="arrow" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M6 18 18 6M6 6h12v12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <a class="destination" href="https://wiki.mud.ren/">
                        <svg class="destination-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 5.5C9 3.5 5.5 3.5 3 4.5v14c2.5-1 6-1 9 1 3-2 6.5-2 9-1v-14c-2.5-1-6-1-9 1Zm0 0v14" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                        </svg>
                        <span class="destination-copy">
                            <span class="destination-title">MUD 百科</span>
                            <span class="domain">wiki.mud.ren</span>
                        </span>
                        <svg class="arrow" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M6 18 18 6M6 6h12v12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                </nav>
            </main>

            <footer class="footer">
                <span>以文字，构筑世界。</span>
                <span class="footer-label">MUDREN / BUILT FOR MUD</span>
            </footer>
        </div>
    </body>
</html>
