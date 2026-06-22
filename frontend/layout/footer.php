<script>

    function toggleSidebar(){

        const sidebar = document.getElementById("sidebar")
        const main = document.getElementById("main") || document.querySelector(".main")
        const btn = document.getElementById("menuBtn")

        if(!sidebar || !btn){
            return
        }

        const isMobile = window.matchMedia && window.matchMedia("(max-width: 768px)").matches

        if(isMobile){
            const isOpen = sidebar.classList.toggle("collapsed")
            document.body.classList.toggle("sidebar-open", isOpen)
            document.body.classList.toggle("sidebar-mobile-open", isOpen)
            btn.classList.toggle("active", isOpen)

            if(main){
                main.classList.add("expanded")
            }

            return
        }

        sidebar.classList.toggle("collapsed")

        if(main){
            main.classList.toggle("expanded")
        }

        btn.classList.toggle("active")

    }

</script>

</body>
</html>
