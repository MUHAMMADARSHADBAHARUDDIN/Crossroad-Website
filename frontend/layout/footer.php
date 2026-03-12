<script>

    function toggleSidebar(){

        const sidebar = document.getElementById("sidebar")
        const main = document.getElementById("main")
        const btn = document.getElementById("menuBtn")

        sidebar.classList.toggle("collapsed")
        main.classList.toggle("expanded")

        btn.classList.toggle("active")

    }

</script>

</body>
</html>
