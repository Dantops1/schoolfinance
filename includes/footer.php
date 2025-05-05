</div> <!-- Close .container from header.php -->

<footer class="bg-light text-center text-lg-start mt-4 py-3">
    <div class="container">
        <div class="row">
            <div class="col-lg-6 col-md-12 mb-3 mb-md-0">
                <h5 class="text-uppercase">School Finance App</h5>
                <p class="text-muted">
                    Manage your school's finances efficiently.
                </p>
            </div>
            <div class="col-lg-6 col-md-12">
                <!-- Optional: Add some links or info here -->
                <!--
                <ul class="list-unstyled mb-0">
                  <li><a href="#" class="text-muted">Link 1</a></li>
                  <li><a href="#" class="text-muted">Link 2</a></li>
                </ul>
                -->
            </div>
        </div>
    </div>
    <div class="text-center p-3 bg-dark text-white-50"> <!-- Using bg-dark for contrast -->
        © <?php echo date("Y"); ?> School Finance App
    </div>
</footer>

<!-- Link to Bootstrap JS (Use CDN or local file) -->
<!-- Place this just before the closing </body> tag -->
<!-- Bootstrap 5.3.2 bundle includes Popper.js -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<!-- Link to Custom JS -->
<!-- Path is relative to the *calling script's* directory -->
<script src="js/script.js"></script>

</body>
</html>