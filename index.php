<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ASD Academy - Student Portal</title>
  <meta name="description" content="Student management portal for ASD Academy">
  <link rel="stylesheet" href="../assets/css/tailwind.min.css">
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'sans-serif'],
          },
          colors: {
            primary: {
              50: '#f0f9ff',
              100: '#e0f2fe',
              200: '#bae6fd',
              300: '#7dd3fc',
              400: '#38bdf8',
              500: '#0ea5e9',
              600: '#0284c7',
              700: '#0369a1',
              800: '#075985',
              900: '#0c4a6e',
            },
            secondary: {
              50: '#f5f3ff',
              100: '#ede9fe',
              200: '#ddd6fe',
              300: '#c4b5fd',
              400: '#a78bfa',
              500: '#8b5cf6',
              600: '#7c3aed',
              700: '#6d28d9',
              800: '#5b21b6',
              900: '#4c1d95',
            }
          }
        }
      }
    }
  </script>
  <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
  <!-- Custom Styles -->
  <style>
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .animate-fade-in {
      opacity: 0;
      animation: fadeIn 0.8s ease-out forwards;
    }
    
    .btn-hover {
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      overflow: hidden;
    }
    
    .btn-hover:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }
    
    .btn-hover:active {
      transform: translateY(0);
    }
    
    .feature-card {
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.95) 100%);
      backdrop-filter: blur(10px);
    }
    
    .feature-card:hover {
      transform: translateY(-8px);
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }
    
    .gradient-text {
      background: linear-gradient(135deg, #0ea5e9 0%, #7c3aed 100%);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    
    .gradient-bg {
      background: linear-gradient(135deg, #0ea5e9 0%, #7c3aed 100%);
    }
    
    .glass-effect {
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
    }
    
    .section-spacing {
      padding-top: 5rem;
      padding-bottom: 5rem;
    }
    
    @media (max-width: 768px) {
      .section-spacing {
        padding-top: 3rem;
        padding-bottom: 3rem;
      }
    }
  </style>
</head>
<body class="bg-gray-50 font-sans antialiased text-gray-800">
  <!-- Preloader -->
  <div id="preloader" class="fixed inset-0 bg-white z-50 flex items-center justify-center">
    <div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-primary-600"></div>
  </div>

  <!-- Navigation -->
  <nav class="bg-white/80 shadow-sm sticky top-0 z-40 backdrop-blur-md">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center h-16">
        <!-- Logo -->
        <div class="flex-shrink-0 flex items-center">
          <a href="#" class="flex items-center space-x-2">
            <img src="assets/images/logo.png" alt="ASD Academy Logo" class="h-8 w-auto">
            <span class="text-xl font-bold gradient-text">ASD Academy</span>
          </a>
        </div>
        
        <!-- Login Button -->
        <div>
          <a href="login.php" class="gradient-bg text-white px-4 py-2 rounded-md hover:shadow-lg transition duration-300 btn-hover">
            <i class="fas fa-sign-in-alt mr-2"></i> Login
          </a>
        </div>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <section class="relative overflow-hidden section-spacing">
    <div class="absolute inset-0 z-0">
      <div class="absolute inset-0 bg-gradient-to-br from-primary-100 to-secondary-100 opacity-90"></div>
      <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80')] bg-cover bg-center mix-blend-overlay opacity-20"></div>
    </div>
    
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
      <div class="flex flex-col md:flex-row items-center">
        <!-- Hero Content -->
        <div class="md:w-1/2 text-center md:text-left mb-10 md:mb-0 animate-fade-in">
          <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4 leading-tight">
            Welcome to <span class="gradient-text">ASD Academy</span> Portal
          </h1>
          <p class="text-xl text-gray-700 mb-8 max-w-lg mx-auto md:mx-0">
            Your gateway to academic excellence and seamless learning management
          </p>
          <div class="flex flex-col sm:flex-row justify-center md:justify-start gap-4">
            <a href="login.php" class="gradient-bg text-white px-6 py-3 rounded-lg font-medium hover:shadow-lg transition duration-300 btn-hover">
              <i class="fas fa-sign-in-alt mr-2"></i> Student Login
            </a>
            <a href="log.php" class="bg-white text-primary-600 border border-primary-200 px-6 py-3 rounded-lg font-medium hover:bg-primary-50 transition duration-300 btn-hover">
              <i class="fas fa-user-tie mr-2"></i> Faculty Login
            </a>
          </div>
        </div>
        
        <!-- Hero Image -->
        <div class="md:w-1/2 animate-fade-in" style="animation-delay: 0.2s;">
          <div class="relative">
            <div class="absolute -inset-4 bg-gradient-to-r from-primary-400 to-secondary-500 rounded-2xl opacity-75 blur-lg"></div>
            <img src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1471&q=80" alt="Students learning" class="relative w-full h-auto rounded-xl shadow-2xl border-4 border-white">
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- About Section -->
  <section class="bg-white section-spacing">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-16">
        <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">About the Portal</h2>
        <div class="w-20 h-1 bg-gradient-to-r from-primary-500 to-secondary-500 mx-auto mb-6"></div>
        <p class="text-xl text-gray-600 max-w-3xl mx-auto">
          Your centralized platform for all academic activities at ASD Academy
        </p>
      </div>
      
      <div class="flex flex-col lg:flex-row items-center gap-12">
        <!-- About Image -->
        <div class="lg:w-1/2">
          <div class="relative">
            <div class="absolute -inset-4 bg-gradient-to-r from-primary-100 to-secondary-100 rounded-2xl opacity-75 blur-lg"></div>
            <img src="https://images.unsplash.com/photo-1588072432836-e10032774350?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1472&q=80" alt="About ASD Academy Portal" class="relative w-full rounded-xl shadow-lg border-4 border-white">
          </div>
        </div>
        
        <!-- About Content -->
        <div class="lg:w-1/2">
          <p class="text-lg text-gray-600 mb-6">
            The ASD Academy Student Portal provides students and faculty with seamless access to academic resources, course materials, and administrative tools in one convenient platform.
          </p>
          
          <div class="space-y-4 mb-8">
            <div class="flex items-start">
              <div class="flex-shrink-0 mt-1">
                <div class="gradient-bg text-white p-1 rounded-full">
                  <i class="fas fa-check-circle text-sm"></i>
                </div>
              </div>
              <div class="ml-3">
                <p class="text-gray-700 font-medium">24/7 access to academic resources and course materials</p>
              </div>
            </div>
            <div class="flex items-start">
              <div class="flex-shrink-0 mt-1">
                <div class="gradient-bg text-white p-1 rounded-full">
                  <i class="fas fa-check-circle text-sm"></i>
                </div>
              </div>
              <div class="ml-3">
                <p class="text-gray-700 font-medium">Real-time tracking of academic progress and performance</p>
              </div>
            </div>
            <div class="flex items-start">
              <div class="flex-shrink-0 mt-1">
                <div class="gradient-bg text-white p-1 rounded-full">
                  <i class="fas fa-check-circle text-sm"></i>
                </div>
              </div>
              <div class="ml-3">
                <p class="text-gray-700 font-medium">Secure communication between students and faculty</p>
              </div>
            </div>
          </div>
          
          <a href="https://asdacademy.in/" class="inline-flex items-center text-primary-600 font-medium hover:text-primary-800 transition duration-300">
            Learn more about our academy
            <i class="fas fa-arrow-right ml-2"></i>
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- Features Section -->
  <section class="bg-gradient-to-br from-gray-50 to-gray-100 section-spacing">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-16">
        <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Portal Features</h2>
        <div class="w-20 h-1 bg-gradient-to-r from-primary-500 to-secondary-500 mx-auto mb-6"></div>
        <p class="text-xl text-gray-600 max-w-3xl mx-auto">
          Everything you need for academic success in one place
        </p>
      </div>
      
      <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
        <!-- Feature 1 -->
         <a href="login.php">
        <div class="feature-card p-8 rounded-xl border border-gray-100 text-center">
          <div class="gradient-text text-4xl mb-6">
            <i class="fas fa-calendar-check"></i>
          </div>
          <h3 class="text-xl font-bold mb-3">Attendance</h3>
          <p class="text-gray-600">
            Track and manage your class attendance records in real-time with detailed analytics.
          </p>
        </div>
  </a>
        <!-- Feature 2 -->
         <a href="login.php">
        <div class="feature-card p-8 rounded-xl border border-gray-100 text-center">
          <div class="gradient-text text-4xl mb-6">
            <i class="fas fa-tasks"></i>
          </div>
          <h3 class="text-xl font-bold mb-3">Assignments</h3>
          <p class="text-gray-600">
            Submit assignments, receive feedback, and track submission deadlines all in one place.
          </p>
        </div>
        </a>
        <!-- Feature 3 -->
         <a href="login.php">
        <div class="feature-card p-8 rounded-xl border border-gray-100 text-center">
          <div class="gradient-text text-4xl mb-6">
            <i class="fas fa-chart-line"></i>
          </div>
          <h3 class="text-xl font-bold mb-3">Grades</h3>
          <p class="text-gray-600">
            View your academic performance with interactive charts and progress reports.
          </p>
        </div>
        </a>
        
        <!-- Feature 4 -->
         <a href="login.php">
        <div class="feature-card p-8 rounded-xl border border-gray-100 text-center">
          <div class="gradient-text text-4xl mb-6">
            <i class="fas fa-bullhorn"></i>
          </div>
          <h3 class="text-xl font-bold mb-3">Notices</h3>
          <p class="text-gray-600">
            Stay updated with important announcements, events, and institutional news.
          </p>
        </div>
      </div>
      </a>
    </div>
  </section>

  <!-- Testimonials Section -->
  <section class="bg-white section-spacing">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-16">
        <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">What Our Users Say</h2>
        <div class="w-20 h-1 bg-gradient-to-r from-primary-500 to-secondary-500 mx-auto mb-6"></div>
        <p class="text-xl text-gray-600 max-w-3xl mx-auto">
          Hear from students and faculty about their experience
        </p>
      </div>
      
      <div class="grid md:grid-cols-2 gap-8">
        <!-- Testimonial 1 -->
        <div class="bg-white p-8 rounded-xl shadow-sm border border-gray-100">
          <div class="flex items-center mb-6">
            <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="Student" class="w-16 h-16 rounded-full object-cover mr-4 border-2 border-primary-100">
            <div>
              <h4 class="font-bold text-lg">Rahul Sharma</h4>
              <p class="text-gray-600">Computer Science Student</p>
              <div class="flex text-yellow-400 mt-1">
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
              </div>
            </div>
          </div>
          <p class="text-gray-700">
            "The portal has revolutionized how I manage my coursework. Having all materials and progress tracking in one place saves me hours each week."
          </p>
        </div>
        
        <!-- Testimonial 2 -->
        <div class="bg-white p-8 rounded-xl shadow-sm border border-gray-100">
          <div class="flex items-center mb-6">
            <img src="https://randomuser.me/api/portraits/women/44.jpg" alt="Faculty" class="w-16 h-16 rounded-full object-cover mr-4 border-2 border-primary-100">
            <div>
              <h4 class="font-bold text-lg">Prof. Priya Patel</h4>
              <p class="text-gray-600">Faculty Member</p>
              <div class="flex text-yellow-400 mt-1">
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star-half-alt"></i>
              </div>
            </div>
          </div>
          <p class="text-gray-700">
            "The grading interface is incredibly intuitive. I can provide detailed feedback to students much faster than before."
          </p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="gradient-bg text-white section-spacing">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
      <h2 class="text-3xl md:text-4xl font-bold mb-6">Ready to Access Your Portal?</h2>
      <p class="text-xl mb-8 max-w-3xl mx-auto">
        Log in now to manage your academic journey with ASD Academy
      </p>
      <a href="login.php" class="inline-block bg-white text-primary-600 px-8 py-4 rounded-lg font-medium hover:bg-gray-100 hover:shadow-lg transition duration-300 btn-hover">
        <i class="fas fa-sign-in-alt mr-2"></i> Login Now
      </a>
    </div>
  </section>

  <!-- Footer -->
  <footer class="bg-gray-900 text-white pt-16 pb-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-12">
        <!-- About Column -->
        <div>
          <div class="flex items-center mb-4">
            <img src="assets/images/logo.png" alt="ASD Academy Logo" class="h-8 mr-2">
            <span class="text-xl font-bold gradient-text">ASD Academy</span>
          </div>
          <p class="text-gray-400 mb-6">
            Empowering students with quality education and comprehensive learning tools.
          </p>
          <div class="flex space-x-4">
            <a href="https://x.com/vlog_hacker" class="text-gray-400 hover:text-white transition duration-300">
              <i class="fab fa-twitter"></i>
            </a>
            <a href="https://www.linkedin.com/company/asdacademy?originalSubdomain=in" class="text-gray-400 hover:text-white transition duration-300">
              <i class="fab fa-linkedin-in"></i>
            </a>
            <a href="https://www.instagram.com/hackervlogofficial/" class="text-gray-400 hover:text-white transition duration-300">
              <i class="fab fa-instagram"></i>
            </a>
            <a href="https://www.youtube.com/@hackervlog" class="text-gray-400 hover:text-white transition duration-300">
              <i class="fab fa-youtube"></i>
            </a>
          </div>
        </div>
        
        <!-- Quick Links Column -->
        <div>
          <h4 class="text-lg font-semibold mb-6">Quick Links</h4>
          <ul class="space-y-3">
            <li><a href="#" class="text-gray-400 hover:text-white transition duration-300">Home</a></li>
            <li><a href="#" class="text-gray-400 hover:text-white transition duration-300">About</a></li>
            <li><a href="login.php" class="text-gray-400 hover:text-white transition duration-300">Login</a></li>
            <li><a href="#" class="text-gray-400 hover:text-white transition duration-300">Help Center</a></li>
          </ul>
        </div>
        
        <!-- Contact Column -->
        <div>
          <h4 class="text-lg font-semibold mb-6">Contact Info</h4>
          <ul class="space-y-3">
            <li class="flex items-start">
              <i class="fas fa-map-marker-alt text-gray-400 mt-1 mr-3"></i>
              <span class="text-gray-400">Akansha Deep Height, Kota, Rajasthan</span>
            </li>
            <li class="flex items-start">
              <i class="fas fa-phone-alt text-gray-400 mt-1 mr-3"></i>
              <span class="text-gray-400">+91 96801 00687</span>
            </li>
            <li class="flex items-start">
              <i class="fas fa-envelope text-gray-400 mt-1 mr-3"></i>
              <span class="text-gray-400">training@asdacademy.in</span>
            </li>
          </ul>
        </div>
      </div>
      
      <div class="border-t border-gray-800 mt-12 pt-8 text-center">
        <p class="text-gray-400">
          &copy; 2025 ASD Academy. All rights reserved.
        </p>
      </div>
    </div>
  </footer>

  <!-- Back to Top Button -->
  <button id="backToTop" class="fixed bottom-8 right-8 gradient-bg text-white p-4 rounded-full shadow-lg opacity-0 invisible transition-all duration-300 hover:shadow-xl">
    <i class="fas fa-arrow-up"></i>
  </button>

  <!-- Scripts -->
  <script>
    // Preloader
    window.addEventListener('load', function() {
      const preloader = document.getElementById('preloader');
      setTimeout(() => {
        preloader.style.opacity = '0';
        preloader.style.visibility = 'hidden';
        setTimeout(() => {
          preloader.style.display = 'none';
        }, 500);
      }, 500);
    });

    // Back to Top Button
    const backToTopButton = document.getElementById('backToTop');
    
    window.addEventListener('scroll', () => {
      if (window.pageYOffset > 300) {
        backToTopButton.classList.remove('opacity-0', 'invisible');
        backToTopButton.classList.add('opacity-100', 'visible');
      } else {
        backToTopButton.classList.remove('opacity-100', 'visible');
        backToTopButton.classList.add('opacity-0', 'invisible');
      }
    });
    
    backToTopButton.addEventListener('click', () => {
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    });

    // Animation on scroll
    const animateOnScroll = () => {
      const elements = document.querySelectorAll('.animate-fade-in');
      
      elements.forEach(element => {
        const elementPosition = element.getBoundingClientRect().top;
        const screenPosition = window.innerHeight / 1.2;
        
        if (elementPosition < screenPosition) {
          element.style.opacity = '1';
          element.style.transform = 'translateY(0)';
        }
      });
    };
    
    window.addEventListener('scroll', animateOnScroll);
    window.addEventListener('load', animateOnScroll);
  </script>
</body>
</html>