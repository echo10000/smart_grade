    </main>
    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
    <!-- Optional: jQuery (for DataTables or simpler DOM manipulation) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.body.classList.add('is-ready');

            var pageLinks = document.querySelectorAll('a[href$=".php"], a[href*=".php?"]');
            pageLinks.forEach(function (link) {
                if (link.target === '_blank' || link.hasAttribute('data-no-loader')) {
                    return;
                }

                link.addEventListener('click', function (event) {
                    var href = link.getAttribute('href');
                    if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
                        return;
                    }

                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    document.body.classList.add('is-loading');
                    document.body.classList.add('is-transitioning');
                });
            });

            window.addEventListener('pageshow', function () {
                document.body.classList.remove('is-loading');
                document.body.classList.remove('is-transitioning');
            });
        });
    </script>
    <!-- Custom scripts placeholder -->
    <?php if (isset($customScripts)) echo $customScripts; ?>
</body>
</html>
