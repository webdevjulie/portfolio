<?php
// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Define page mapping for active states and breadcrumbs
$page_config = [
    'dashboard.php' => ['icon' => 'home', 'name' => 'Dashboard'],
    'profile.php' => ['icon' => 'person', 'name' => 'Profile'],
    'packages.php' => ['icon' => 'inventory_2', 'name' => 'Packages'],
    'transactions.php' => ['icon' => 'receipt_long', 'name' => 'Transactions'],
    'referrals.php' => ['icon' => 'group', 'name' => 'Referrals']
];

// Function to check if current page matches
function isActivePage($page) {
    global $current_page;
    return $current_page === $page ? 'active' : '';
}

// Get current page info for breadcrumb
$current_page_info = $page_config[$current_page] ?? ['icon' => 'home', 'name' => 'Dashboard'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Modern Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary-orange: #ff6b35;
      --light-orange: #ff8a65;
      --dark-orange: #e55100;
      --sidebar-bg: #ffffff;
      --sidebar-shadow: 0 0 20px rgba(255, 107, 53, 0.1);
      --text-primary: #2c3e50;
      --text-secondary: #7f8c8d;
      --hover-bg: rgba(255, 107, 53, 0.1);
      --active-bg: linear-gradient(135deg, #ff6b35, #ff8a65);
      --sidebar-width: 280px;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      margin: 0;
      padding: 0;
      overflow-x: hidden;
    }

    /* Mobile Header */
    .mobile-header {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 60px;
      background: var(--sidebar-bg);
      box-shadow: var(--sidebar-shadow);
      z-index: 1001;
      padding: 0 1rem;
      align-items: center;
      justify-content: space-between;
    }

    .mobile-header h4 {
      color: var(--primary-orange);
      font-weight: 700;
      font-size: 1.2rem;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .mobile-menu-toggle {
      background: none;
      border: none;
      color: var(--primary-orange);
      font-size: 1.5rem;
      cursor: pointer;
      padding: 0.5rem;
      border-radius: 8px;
      transition: all 0.3s ease;
    }

    .mobile-menu-toggle:hover {
      background: var(--hover-bg);
    }

    /* Sidebar Overlay */
    .sidebar-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 999;
      opacity: 0;
      transition: opacity 0.3s ease;
    }

    .sidebar-overlay.show {
      opacity: 1;
    }

    .sidebar {
      position: fixed;
      top: 0;
      left: 0;
      height: 100vh;
      width: var(--sidebar-width);
      background: var(--sidebar-bg);
      box-shadow: var(--sidebar-shadow);
      border-right: 1px solid rgba(255, 107, 53, 0.1);
      display: flex;
      flex-direction: column;
      z-index: 1000;
      transition: transform 0.3s ease;
    }

    .sidebar-header {
      padding: 2rem 1.5rem 1.5rem;
      border-bottom: 1px solid rgba(255, 107, 53, 0.1);
      margin-bottom: 1rem;
    }

    .sidebar-header h4 {
      color: var(--primary-orange);
      font-weight: 700;
      font-size: 1.5rem;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .sidebar-header .material-icons {
      font-size: 2rem;
      background: var(--active-bg);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .content, .main-content {
      margin-left: var(--sidebar-width);
      padding: 2rem;
      background: transparent;
      transition: margin-left 0.3s ease;
    }

    .current-page-indicator {
      padding: 0 1.5rem;
      margin-bottom: 1rem;
      border-bottom: 1px solid rgba(255, 107, 53, 0.1);
      padding-bottom: 1rem;
    }

    .breadcrumb-current {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      color: var(--primary-orange);
      font-weight: 600;
      margin-top: 0.25rem;
    }

    .breadcrumb-current .material-icons {
      font-size: 1rem;
    }

    .nav-pills .nav-item {
      margin-bottom: 0.5rem;
    }

    .nav-pills .nav-link {
      color: var(--text-primary);
      padding: 1rem 1.5rem;
      border-radius: 12px;
      font-weight: 500;
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      transition: all 0.3s ease;
      border: none;
      position: relative;
      overflow: hidden;
      text-decoration: none;
    }

    .nav-pills .nav-link::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 0;
      height: 100%;
      background: var(--active-bg);
      transition: width 0.3s ease;
      z-index: -1;
    }

    .nav-pills .nav-link:hover {
      color: var(--primary-orange);
      background: var(--hover-bg);
      transform: translateX(5px);
      border-left: 2px solid var(--light-orange);
    }

    .nav-pills .nav-link.active {
      color: white;
      background: var(--active-bg);
      box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
      transform: translateX(5px);
      position: relative;
      border-left: 4px solid var(--dark-orange);
    }

    .nav-pills .nav-link.active::before {
      width: 100%;
    }

    .nav-pills .nav-link.active::after {
      content: '';
      position: absolute;
      right: -1px;
      top: 50%;
      transform: translateY(-50%);
      width: 0;
      height: 0;
      border-top: 8px solid transparent;
      border-bottom: 8px solid transparent;
      border-right: 8px solid #f8f9fa;
      z-index: 10;
    }

    .nav-pills .nav-link .material-icons {
      font-size: 1.25rem;
      transition: all 0.3s ease;
    }

    .nav-pills .nav-link:hover .material-icons {
      transform: scale(1.1);
    }

    .nav-pills .nav-link.active .material-icons {
      transform: scale(1.1);
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .logout-link {
      margin-top: auto;
      padding-top: 2rem;
      border-top: 1px solid rgba(255, 107, 53, 0.1);
    }

    .logout-link .nav-link {
      color: #dc3545 !important;
      background: rgba(220, 53, 69, 0.05);
    }

    .logout-link .nav-link:hover {
      color: white !important;
      background: #dc3545 !important;
      transform: translateX(5px);
    }

    .content-card {
      background: white;
      border-radius: 16px;
      padding: 2rem;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
      border: 1px solid rgba(255, 107, 53, 0.1);
    }

    .welcome-text {
      color: var(--text-primary);
      font-size: 1.5rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .welcome-subtitle {
      color: var(--text-secondary);
      font-size: 1rem;
    }

    /* Page transition effect */
    .page-transition {
      opacity: 0;
      transform: translateY(20px);
      animation: fadeInUp 0.5s ease forwards;
    }

    @keyframes fadeInUp {
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Custom scrollbar */
    .sidebar::-webkit-scrollbar {
      width: 6px;
    }

    .sidebar::-webkit-scrollbar-track {
      background: transparent;
    }

    .sidebar::-webkit-scrollbar-thumb {
      background: var(--primary-orange);
      border-radius: 3px;
    }

    /* Loading state for navigation */
    .nav-link.loading {
      pointer-events: none;
      opacity: 0.7;
    }

    .nav-link.loading::after {
      content: '';
      position: absolute;
      top: 50%;
      right: 1rem;
      width: 16px;
      height: 16px;
      border: 2px solid transparent;
      border-top: 2px solid currentColor;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% { transform: translateY(-50%) rotate(0deg); }
      100% { transform: translateY(-50%) rotate(360deg); }
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .mobile-header {
        display: flex;
      }

      .sidebar {
        transform: translateX(-100%);
        z-index: 1001;
      }

      .sidebar.show {
        transform: translateX(0);
      }

      .sidebar-overlay {
        display: block;
      }

      .content, .main-content {
        margin-left: 0;
        padding: 1rem;
        padding-top: 80px; /* Account for mobile header */
      }

      .sidebar-header {
        padding: 1.5rem 1.5rem 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
      }

      .sidebar-header h4 {
        font-size: 1.3rem;
      }

      .sidebar-close {
        background: none;
        border: none;
        color: var(--text-secondary);
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 8px;
        transition: all 0.3s ease;
      }

      .sidebar-close:hover {
        background: var(--hover-bg);
        color: var(--primary-orange);
      }

      .nav-pills .nav-link {
        padding: 0.875rem 1.5rem;
        font-size: 0.9rem;
      }

      .nav-pills .nav-link:hover {
        transform: none;
      }

      .nav-pills .nav-link.active {
        transform: none;
      }

      .nav-pills .nav-link.active::after {
        display: none;
      }

      .logout-link {
        padding-top: 1.5rem;
      }

      .content-card {
        padding: 1.5rem;
        border-radius: 12px;
      }

      .welcome-text {
        font-size: 1.3rem;
      }
    }

    @media (max-width: 576px) {
      .content, .main-content {
        padding: 0.75rem;
        padding-top: 75px;
      }

      .content-card {
        padding: 1.25rem;
      }

      .welcome-text {
        font-size: 1.2rem;
      }

      .mobile-header h4 {
        font-size: 1.1rem;
      }

      .sidebar-header h4 {
        font-size: 1.2rem;
      }

      .nav-pills .nav-link {
        padding: 0.75rem 1.25rem;
        font-size: 0.85rem;
      }
    }

    /* Smooth animations */
    * {
      transition: all 0.3s ease;
    }

    /* Prevent body scroll when sidebar is open on mobile */
    body.sidebar-open {
      overflow: hidden;
    }

    @media (min-width: 769px) {
      body.sidebar-open {
        overflow: auto;
      }
    }
  </style>
</head>
<body>

  <!-- Mobile Header -->
  <div class="mobile-header">
    <h4>
      <span class="material-icons">account_balance_wallet</span>
      Investment Portal
    </h4>
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
      <span class="material-icons">menu</span>
    </button>
  </div>

  <!-- Sidebar Overlay -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- Sidebar -->
  <nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <h4>
        <span class="material-icons">account_balance_wallet</span>
        Investment Portal
      </h4>
      <button class="sidebar-close d-md-none" id="sidebarClose">
        <span class="material-icons">close</span>
      </button>
    </div>
    
    <div class="flex-grow-1 px-3">
      <ul class="nav nav-pills flex-column">
        <li class="nav-item">
          <a href="dashboard.php" class="nav-link <?= isActivePage('dashboard.php') ?>">
            <span class="material-icons">home</span>
            Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a href="packages.php" class="nav-link <?= isActivePage('packages.php') ?>">
            <span class="material-icons">inventory_2</span>
            Packages
          </a>
        </li>
        <li class="nav-item">
          <a href="referrals.php" class="nav-link <?= isActivePage('referrals.php') ?>">
            <span class="material-icons">group</span>
            Referrals
          </a>
        </li>
        <li class="nav-item">
          <a href="withdrawals.php" class="nav-link <?= isActivePage('withdrawals.php') ?>">
            <span class="material-icons">money</span>
            Withdrawals
          </a>
        </li>
        <li class="nav-item">
          <a href="history.php" class="nav-link <?= isActivePage('history.php') ?>">
            <span class="material-icons">history</span>
            History
          </a>
        </li>
        <li class="nav-item">
          <a href="profile.php" class="nav-link <?= isActivePage('profile.php') ?>">
            <span class="material-icons">person</span>
            Profile
          </a>
        </li>
      </ul>
    </div>

    <div class="logout-link px-3 pb-3">
      <ul class="nav nav-pills flex-column">
        <li class="nav-item">
          <a href="#" id="logoutLink" class="nav-link">
            <span class="material-icons">logout</span>
            Logout
          </a>
        </li>
      </ul>
    </div>
  </nav>

  <!-- Logout Confirmation Modal -->
  <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          Are you sure you want to logout?
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <a href="../auth/logout.php" class="btn btn-danger">Logout</a>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const sidebar = document.getElementById('sidebar');
      const sidebarOverlay = document.getElementById('sidebarOverlay');
      const mobileMenuToggle = document.getElementById('mobileMenuToggle');
      const sidebarClose = document.getElementById('sidebarClose');
      const body = document.body;

      // Mobile menu toggle
      function toggleSidebar() {
        sidebar.classList.toggle('show');
        sidebarOverlay.classList.toggle('show');
        body.classList.toggle('sidebar-open');
      }

      function closeSidebar() {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
        body.classList.remove('sidebar-open');
      }

      // Event listeners
      mobileMenuToggle.addEventListener('click', toggleSidebar);
      sidebarClose.addEventListener('click', closeSidebar);
      sidebarOverlay.addEventListener('click', closeSidebar);

      // Close sidebar when clicking on navigation links on mobile
      const navLinks = document.querySelectorAll('.nav-link:not(.logout-link .nav-link)');
      navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
          // Close sidebar on mobile when navigating
          if (window.innerWidth <= 768) {
            setTimeout(closeSidebar, 150);
          }
          
          // Add loading state
          this.classList.add('loading');
          setTimeout(() => {
            this.classList.remove('loading');
          }, 1000);
        });
      });

      // Close sidebar on window resize if it becomes desktop size
      window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
          closeSidebar();
        }
      });

      // Prevent body scroll when sidebar is open on mobile
      function preventBodyScroll(e) {
        if (window.innerWidth <= 768 && body.classList.contains('sidebar-open')) {
          e.preventDefault();
        }
      }

      // Add touch support for mobile
      let touchStartX = 0;
      let touchEndX = 0;

      document.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
      });

      document.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        handleSwipe();
      });

      function handleSwipe() {
        const swipeThreshold = 50;
        const swipeDistance = touchEndX - touchStartX;

        if (window.innerWidth <= 768) {
          // Swipe right to open sidebar (from left edge)
          if (swipeDistance > swipeThreshold && touchStartX < 50 && !sidebar.classList.contains('show')) {
            toggleSidebar();
          }
          // Swipe left to close sidebar
          else if (swipeDistance < -swipeThreshold && sidebar.classList.contains('show')) {
            closeSidebar();
          }
        }
      }

      // Logout functionality
      document.getElementById('logoutLink').addEventListener('click', function (e) {
        e.preventDefault();
        var logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
        logoutModal.show();
      });

      // Add smooth page transition
      const content = document.querySelector('.page-transition');
      if (content) {
        content.style.opacity = '0';
        content.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
          content.style.opacity = '1';
          content.style.transform = 'translateY(0)';
        }, 100);
      }

      // Highlight current page in navigation
      const currentPath = window.location.pathname.split('/').pop();
      navLinks.forEach(link => {
        const linkPath = link.getAttribute('href');
        if (linkPath === currentPath) {
          link.classList.add('active');
        }
      });
    });

    // Add keyboard navigation support
    document.addEventListener('keydown', function(e) {
      // ESC key to close sidebar on mobile
      if (e.key === 'Escape' && window.innerWidth <= 768) {
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('show')) {
          sidebar.classList.remove('show');
          document.getElementById('sidebarOverlay').classList.remove('show');
          document.body.classList.remove('sidebar-open');
        }
      }

      // Keyboard shortcuts for navigation (Ctrl/Cmd + number)
      if (e.ctrlKey || e.metaKey) {
        const shortcuts = {
          '1': 'dashboard.php',
          '2': 'packages.php',
          '3': 'referrals.php',
          '4': 'withdrawals.php',
          '5': 'history.php',
          '6': 'profile.php'
        };
        
        if (shortcuts[e.key]) {
          e.preventDefault();
          window.location.href = shortcuts[e.key];
        }
      }
    });
  </script>
</body>
</html>