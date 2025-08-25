<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebCash Investment</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#home">
                <i class="fas fa-chart-line"></i>
                WebCash Investment
            </a>
            
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <i class="fas fa-bars text-primary"></i>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#packages">Packages</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contacts</a>
                    </li>
                </ul>
                <div class="d-flex gap-2">
                    <a href="./auth/login.php" class="btn btn-outline-primary">
                        <i class="fas fa-user me-2"></i>Login
                    </a>
                    <a href="#packages" class="btn btn-primary">
                        Get Started
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <div class="hero-content fade-in-up">
                        <h1 class="hero-title">
                            Grow Your Wealth with Smart Investment Strategies
                        </h1>
                        <div class="d-flex gap-3 flex-wrap">
                            <a href="#packages" class="btn btn-primary btn-lg">
                                <i class="fas fa-rocket me-2"></i>Start Investing
                            </a>
                            <a href="#about" class="btn btn-outline-primary btn-lg">
                                <i class="fas fa-play me-2"></i>Learn More
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="section-padding bg-light">
        <div class="container">
            <div class="fade-in-up">
                <h2 class="section-title">Why Choose WebCash Investment?</h2>
                <p class="section-subtitle">A reputable investment platform committed to your financial growth and security.</p>
            </div>
            <div class="row">
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="modern-card fade-in-up">
                        <div class="feature-icon">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <h4 class="mb-3 text-center">Professional Management</h4>
                        <p class="text-center text-muted">Managed by experienced financial professionals with a strong background in investments and capital growth strategies.</p>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="modern-card fade-in-up">
                        <div class="feature-icon">
                            <i class="fas fa-thumbs-up"></i>
                        </div>
                        <h4 class="mb-3 text-center">Trusted by Many</h4>
                        <p class="text-center text-muted">Built on integrity and transparency, trusted by thousands of clients for consistent and reliable returns.</p>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="modern-card fade-in-up">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4 class="mb-3 text-center">Consistent Growth</h4>
                        <p class="text-center text-muted">Delivering stable and measurable investment results through disciplined strategies and client-focused services.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <!-- About Section Content for WebCash Investment -->
    <section id="about" class="section-padding">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-5 mb-lg-0">
                    <div class="fade-in-up">
                        <h2 class="mb-4">Focused on Growth, Centered on You</h2>
                        <p class="lead mb-4">
                            At WebCash Investment, we're here to help you take control of your financial journey. We offer straightforward, goal-based investment solutions tailored to individuals and groups looking to grow their resources responsibly.
                        </p>
                        <p class="mb-4">
                            Our team is dedicated to guiding you with clarity, transparency, and practical strategies — putting your goals at the center of everything we do. Whether you're starting small or building bigger, we’re committed to supporting your next financial steps.
                        </p>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="fade-in-up">
                        <div class="position-relative">
                            <div class="modern-card p-4 mb-4" style="border-left: 5px solid var(--primary-orange);">
                                <div class="d-flex align-items-center">
                                    <div class="feature-icon me-4" style="width: 60px; height: 60px; font-size: 1.5rem;">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-2">Goal-Based Growth</h5>
                                        <p class="mb-0 text-muted">We focus on helping you meet personal or group financial goals with steady, achievable strategies.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="modern-card p-4 mb-4" style="border-left: 5px solid var(--accent-orange);">
                                <div class="d-flex align-items-center">
                                    <div class="feature-icon me-4" style="width: 60px; height: 60px; font-size: 1.5rem; background: linear-gradient(135deg, var(--accent-orange), var(--primary-orange));">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-2">Responsive Support Team</h5>
                                        <p class="mb-0 text-muted">We’re available to guide you with clear information and personalized support at every step.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="modern-card p-4" style="border-left: 5px solid var(--primary-orange-dark);">
                                <div class="d-flex align-items-center">
                                    <div class="feature-icon me-4" style="width: 60px; height: 60px; font-size: 1.5rem; background: linear-gradient(135deg, var(--primary-orange-dark), var(--primary-orange));">
                                        <i class="fas fa-handshake"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-2">Transparent & Responsible</h5>
                                        <p class="mb-0 text-muted">We operate with clear terms and honest practices to ensure trust and confidence in every transaction.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            
            <!-- Call to Action -->
            <div class="row mt-5 pt-5">
                <div class="col-12">
                    <div class="modern-card text-center" style="background: var(--gradient-primary); color: white; border: none;">
                        <div class="fade-in-up">
                            <h3 class="mb-4" style="color: white;">Ready to Start Your Investment Journey?</h3>
                            <p class="mb-4" style="color: rgba(255,255,255,0.9); font-size: 1.125rem;">
                                Begin your investment journey with WebCash Investment — a platform built on transparency, security, and growth potential. Start with as little as Rs5,000 and take a step toward your financial goals.
                            </p>
                            <div class="d-flex gap-3 justify-content-center flex-wrap">
                                <a href="#packages" class="btn btn-light btn-lg">
                                    <i class="fas fa-rocket me-2"></i>View Investment Packages
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Packages Section -->
    <section id="packages" class="section-padding bg-light">
        <div class="container">
            <div class="fade-in-up">
                <h2 class="section-title">WebCash Investment Packages</h2>
                <p class="section-subtitle">Choose the perfect package to match your investment goals and budget</p>
            </div>
            <div class="row">
                <?php
                include './includes/db.php'; // adjust to your DB connection
                $query = "SELECT * FROM packages";
                $result = mysqli_query($conn, $query);

                while ($row = mysqli_fetch_assoc($result)) {
                ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="package-card fade-in-up">
                            <div class="text-center mb-4">
                                <h4 class="mb-3"><?= htmlspecialchars($row['name']) ?></h4>
                                <div class="package-price">RS<?= number_format($row['amount'], 2) ?></div>
                                <p class="text-muted"><?= htmlspecialchars($row['description']) ?></p>
                            </div>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <form action="invest.php" method="post">
                                    <input type="hidden" name="package_id" value="<?= $row['id'] ?>">
                                    <button class="btn btn-outline-primary w-100">Invest Now</button>
                                </form>
                            <?php else: ?>
                                <a href="./auth/register.php" class="btn btn-outline-primary w-100">Signup to Invest</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer" id="contact">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5><i class="fas fa-chart-line me-2"></i>WebCash Investment</h5>
                    <p class="text-white mb-3">
                        Building wealth through intelligent investment strategies since 2015. Your trusted partner in achieving financial freedom.
                    </p>
                    <!-- <div class="social-icons">
                        <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    </div> -->
                </div>
                <div class="col-lg-2 col-md-6 mb-4">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="#home">Home</a></li>
                        <li class="mb-2"><a href="#about">About Us</a></li>
                        <li class="mb-2"><a href="#packages">Packages</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 mb-4">
                    <h6>Contact Info</h6>
                    <ul class="list-unstyled">
                        <!-- <li class="mb-2"><i class="fas fa-map-marker-alt me-2 text-primary"></i>123 Business District, Makati City</li>
                        <li class="mb-2"><i class="fas fa-phone me-2 text-primary"></i>+63 2 8123 4567</li> -->
                        <li class="mb-2"><i class="fas fa-envelope me-2 text-primary"></i>webcashinvestment@gmail.com</li>
                        <li class="mb-2"><i class="fas fa-clock me-2 text-primary"></i>Mon-Fri 8AM-6PM</li>
                    </ul>
                </div>
            </div>
            <hr class="my-4 border-secondary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="mb-0">&copy; 2025 WebCash Investment. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Package selection handler
        function selectPackage(name, amount) {
            const modal = document.createElement('div');
            modal.className = 'position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center';
            modal.style.backgroundColor = 'rgba(0,0,0,0.8)';
            modal.style.zIndex = '9999';
            modal.innerHTML = `
                <div class="bg-white rounded-4 p-4 m-3" style="max-width: 400px;">
                    <div class="text-center">
                        <div class="feature-icon mb-3" style="width: 60px; height: 60px;">
                            <i class="fas fa-check"></i>
                        </div>
                        <h4 class="mb-3">Great Choice!</h4>
                        <p class="mb-3">You selected the <strong>${name} Package</strong> for <strong>₱${amount.toLocaleString()}</strong></p>
                        <p class="text-muted mb-4">Please contact us to proceed with your investment.</p>
                        <div class="d-flex gap-2 justify-content-center">
                            <a href="tel:+6328123456" class="btn btn-primary">
                                <i class="fas fa-phone me-2"></i>Call Us
                            </a>
                            <a href="mailto:info@investpro.com" class="btn btn-outline-primary">
                                <i class="fas fa-envelope me-2"></i>Email
                            </a>
                            <button class="btn btn-outline-secondary" onclick="this.closest('.position-fixed').remove()">
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Navbar scroll effects
        const navbar = document.querySelector('.navbar');
        
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
            
            updateActiveNavLink();
        });

        // Update active navigation link
        function updateActiveNavLink() {
            const sections = document.querySelectorAll('section[id]');
            const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
            
            let current = '';
            sections.forEach(section => {
                const sectionTop = section.offsetTop - 200;
                const sectionHeight = section.clientHeight;
                if (window.scrollY >= sectionTop && window.scrollY < sectionTop + sectionHeight) {
                    current = section.getAttribute('id');
                }
            });

            navLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + current) {
                    link.classList.add('active');
                }
            });
        }

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animated');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.fade-in-up').forEach(el => observer.observe(el));

        // Counter animation
        function animateCounters() {
            const counters = document.querySelectorAll('.stat-number');
            
            counters.forEach(counter => {
                const target = counter.textContent;
                let value = 0;
                let increment;
                
                if (target.includes('₱')) {
                    increment = 0.1;
                    const endValue = 2.5;
                    const timer = setInterval(() => {
                        value += increment;
                        if (value >= endValue) {
                            value = endValue;
                            clearInterval(timer);
                        }
                        counter.textContent = '₱' + value.toFixed(1) + 'B+';
                    }, 50);
                } else if (target.includes('K+')) {
                    increment = 200;
                    const endValue = 10000;
                    const timer = setInterval(() => {
                        value += increment;
                        if (value >= endValue) {
                            value = endValue;
                            clearInterval(timer);
                        }
                        counter.textContent = (value / 1000).toFixed(0) + 'K+';
                    }, 50);
                } else if (target.includes('%')) {
                    increment = 0.3;
                    const endValue = 15;
                    const timer = setInterval(() => {
                        value += increment;
                        if (value >= endValue) {
                            value = endValue;
                            clearInterval(timer);
                        }
                        counter.textContent = Math.floor(value) + '%';
                    }, 100);
                }
            });
        }

        // Initialize counter animation
        const heroObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounters();
                    heroObserver.disconnect();
                }
            });
        }, { threshold: 0.5 });

        heroObserver.observe(document.querySelector('.hero-section'));

        // Mobile menu auto-close
        document.querySelectorAll('.navbar-nav .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                const navbarCollapse = document.querySelector('.navbar-collapse');
                if (navbarCollapse.classList.contains('show')) {
                    const bsCollapse = new bootstrap.Collapse(navbarCollapse);
                    bsCollapse.hide();
                }
            });
        });

        // Initialize animations on load
        window.addEventListener('load', function() {
            setTimeout(() => {
                document.querySelectorAll('.hero-section .fade-in-up').forEach((el, index) => {
                    setTimeout(() => {
                        el.classList.add('animated');
                    }, index * 200);
                });
            }, 100);
        });
    </script>
</body>
</html>