<svg class="error-art art-{{ $illustration }}" viewBox="0 0 260 200" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <defs>
        <linearGradient id="paper" x1="60" y1="30" x2="170" y2="180" gradientUnits="userSpaceOnUse"><stop class="paper-top"/><stop class="paper-bottom" offset="1"/></linearGradient>
        <linearGradient id="blue" x1="65" y1="35" x2="175" y2="165" gradientUnits="userSpaceOnUse"><stop stop-color="#a8d9fa"/><stop offset="1" stop-color="#619ddd"/></linearGradient>
        <linearGradient id="mint" x1="135" y1="95" x2="210" y2="170" gradientUnits="userSpaceOnUse"><stop stop-color="#d0f3e8"/><stop offset="1" stop-color="#72c8bc"/></linearGradient>
    </defs>
    <ellipse class="art-halo" cx="130" cy="99" rx="91" ry="80"/>
    <ellipse class="art-shadow" cx="132" cy="182" rx="65" ry="7"/>
    <circle class="art-dot" cx="38" cy="107" r="3"/><circle class="art-dot" cx="218" cy="56" r="4"/>
    <path class="art-spark" d="M53 44v10m-5-5h10M213 132v8m-4-4h8"/>
    <g class="art-object" stroke-width="2">
        @switch($illustration)
            @case('not-found')
                <path class="art-depth" d="M80 36h65l29 29v98a10 10 0 0 1-10 10H80a10 10 0 0 1-10-10V46a10 10 0 0 1 10-10Z"/>
                <path class="art-paper" d="M73 29h65l29 29v98a10 10 0 0 1-10 10H73a10 10 0 0 1-10-10V39a10 10 0 0 1 10-10Z"/>
                <path class="art-fold" d="M138 29v20a9 9 0 0 0 9 9h20"/>
                <path class="art-lines" d="M83 73h48M83 89h35M83 105h26"/>
                <g class="search-glass">
                    <path d="m170 134 27 28" stroke="#3e80ba" stroke-width="15"/>
                    <path d="m170 132 27 28" stroke="url(#blue)" stroke-width="11"/>
                    <circle cx="151" cy="111" r="34" fill="url(#mint)" stroke="#69b7b1"/>
                    <circle class="lens" cx="151" cy="111" r="26"/>
                    <path d="M133 108a19 19 0 0 1 18-16" stroke="#fff" stroke-width="3"/>
                </g>
                @break
            @case('restricted')
                <path class="art-depth" d="m134 30 60 23v48c0 36-30 60-60 77-30-17-60-41-60-77V53Z"/>
                <path class="shield-body" d="m127 23 60 23v48c0 36-30 60-60 77-30-17-60-41-60-77V46Z" fill="url(#blue)" stroke="#6b9ecd"/>
                <path d="m127 36 47 18v40c0 29-23 50-47 64-24-14-47-35-47-64V54Z" stroke="#e5f5ff"/>
                <g class="shield-lock"><path d="M111 91V77a16 16 0 0 1 32 0v14" stroke="#f5fbff" stroke-width="7"/><rect x="100" y="89" width="54" height="43" rx="10" fill="url(#paper)" stroke="#83accf"/><circle cx="127" cy="107" r="4" fill="#4c88b8"/><path d="M127 110v8" stroke="#4c88b8" stroke-width="3"/></g>
                @break
            @case('session')
                <circle class="art-depth" cx="120" cy="105" r="66"/>
                <circle cx="115" cy="98" r="66" fill="url(#blue)" stroke="#7aabd5"/>
                <circle class="art-paper" cx="115" cy="98" r="55"/>
                <path class="art-lines" d="M115 51v6M115 139v6M68 98h6M156 98h6"/>
                <path class="clock-hand" d="M115 98V68" stroke="#427db7" stroke-width="4"/>
                <path d="m115 98 21 12" stroke="#427db7" stroke-width="4"/><circle cx="115" cy="98" r="4" fill="#427db7"/>
                <path d="m179 116 29 11v23c0 16-15 28-29 36-14-8-29-20-29-36v-23Z" fill="url(#mint)" stroke="#68b3aa"/><path d="m167 150 8 8 16-20" stroke="#287f7c" stroke-width="3"/>
                @break
            @case('rate-limit')
                <rect class="art-depth" x="62" y="40" width="132" height="126" rx="20"/>
                <rect class="art-paper" x="55" y="33" width="132" height="126" rx="20"/>
                <path class="art-lines" d="M74 62h47"/>
                <rect class="activity bar-one" x="77" y="99" width="18" height="38" rx="7" fill="url(#blue)"/>
                <rect class="activity bar-two" x="108" y="78" width="18" height="59" rx="7" fill="url(#blue)"/>
                <rect class="activity bar-three" x="139" y="88" width="18" height="49" rx="7" fill="url(#mint)"/>
                <circle cx="183" cy="145" r="31" fill="url(#mint)" stroke="#6cb8ae"/><circle class="art-paper" cx="183" cy="145" r="23"/><path d="M183 130v15l10 6" stroke="#398f8d" stroke-width="3"/>
                @break
            @case('system')
                <rect class="art-depth" x="61" y="39" width="142" height="125" rx="19"/>
                <rect class="art-paper" x="54" y="32" width="142" height="125" rx="19"/>
                <rect x="68" y="48" width="113" height="35" rx="9" fill="url(#blue)"/><rect x="68" y="98" width="113" height="35" rx="9" fill="url(#blue)"/>
                <path d="M82 65h36M82 115h36" stroke="#f7fcff" stroke-width="3"/><circle cx="166" cy="65" r="4" fill="#f7fcff"/><circle cx="166" cy="115" r="4" fill="#f7fcff"/>
                <g class="warning"><path d="m181 117 31 52a8 8 0 0 1-7 12h-62a8 8 0 0 1-7-12l31-52a8 8 0 0 1 14 0Z" fill="url(#mint)" stroke="#6db5ac"/><path d="M174 138v17m0 10h.01" stroke="#287f7c" stroke-width="4"/></g>
                @break
            @case('maintenance')
                <path class="art-depth" d="m125 30 58 22v47c0 35-29 58-58 75-29-17-58-40-58-75V52Z"/>
                <path d="m118 23 58 22v47c0 35-29 58-58 75-29-17-58-40-58-75V45Z" fill="url(#blue)" stroke="#6b9ecd"/>
                <path d="m118 36 45 17v39c0 28-22 48-45 62-23-14-45-34-45-62V53Z" stroke="#e5f5ff"/><path d="m97 90 15 15 29-34" stroke="#f6fcff" stroke-width="6"/>
                <g class="gear"><path d="m172 110 3-9h14l3 9 8 5 10-2 7 12-6 8v10l6 8-7 12-10-2-8 5-3 9h-14l-3-9-8-5-10 2-7-12 6-8v-10l-6-8 7-12 10 2Z" fill="url(#mint)" stroke="#6bb4ac"/><circle class="art-paper" cx="182" cy="138" r="15"/><circle cx="182" cy="138" r="6" fill="#83c6be"/></g>
                @break
        @endswitch
    </g>
</svg>
