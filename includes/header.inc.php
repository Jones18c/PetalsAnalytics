<?php
#ob_start();
session_start();
$_SESSION['ref'] = $_SERVER['SCRIPT_NAME'];

// Load security first to set up guest session if needed
require(__DIR__ . '/config.inc.php');
require(__DIR__ . '/security.inc.php');
require(__DIR__ . '/functions.inc.php');

// Ensure username is set (security.inc.php should have set it up, but double-check)
if(!isset($_SESSION['username'])) {
  $_SESSION['username'] = 'guest';
  $_SESSION['valid'] = true;
  $_SESSION['fullName'] = 'Guest User';
  $_SESSION['emailAddress'] = 'guest@localhost';
  $_SESSION['locationName'] = 'All Locations';
}

if(true) { // Always proceed - no redirect in simple mode

  global $header;
  if (!isset($header) || !isset($header['securityModuleName'])) {
    $header = array(
      'pageTitle' => 'Analytics',
      'securityModuleName' => 'Core'
    );
  }

  // Access check DISABLED in simple mode - everyone has access
  // if (getAccess($_SESSION['username'],$header['securityModuleName']) == 0) {
  //     echo "Permission Denied...";
  //     exit;
  // }
}


#appLog($_SESSION['username'],$header['pageTitle']);
?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Mayesh Analytics Tools">
    <meta name="author" content="Chris Cunningham">

    <title><?php echo $header['pageTitle'] ?? 'Analytics'; ?></title>
    
    <!-- Preconnect to CDNs for faster loading -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://code.jquery.com" crossorigin>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/start/jquery-ui.min.css">

    <!-- Load scripts with defer for better performance -->
    <script defer src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script defer src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.6/dist/loadingoverlay.min.js"></script>
 
 <script defer>
    // Wait for all deferred scripts to load
    window.addEventListener('DOMContentLoaded', function() {
        // Wait for jQuery to be available
        function initWhenReady() {
            if (typeof jQuery === 'undefined') {
                setTimeout(initWhenReady, 50);
                return;
            }
            
            // Session keep-alive
            var refreshTime = 1200000; // every 10 minutes in milliseconds
            window.setInterval(function() {
                jQuery.ajax({
                    cache: false,
                    type: "GET",
                    url: "/includes/Session.php",
                    success: function(data) {
                        console.log("Session updated..");
                    }
                });
            }, refreshTime);

            jQuery(document).ready(function($) {
                // Only initialize DataTables if elements exist
                if ($('#exportTable').length) $('#exportTable').DataTable();
                if ($('#salesBudgets').length) $('#salesBudgets').DataTable();
                if ($('#historicalTopCustomers').length) {
                    $('#historicalTopCustomers').DataTable({
                        "order": [[ 2, "desc" ]],
                        paging: false
                    });
                }
                $('#loading').hide();
                $('#latest-features').show();
                
                // Global loading modal handler for forms with class 'loading-form'
                $('form.loading-form, form[data-show-loading="true"]').on('submit', function(e) {
                    $('body').addClass('loading-cursor');
                    const loadingModal = new bootstrap.Modal(document.getElementById('globalLoadingModal'));
                    loadingModal.show();
                });
                
                // Also handle any button with class 'show-loading'
                $('.show-loading').on('click', function() {
                    $('body').addClass('loading-cursor');
                    const loadingModal = new bootstrap.Modal(document.getElementById('globalLoadingModal'));
                    loadingModal.show();
                });
                
                // Handle submenu toggle
                $('.submenu-toggle').on('click', function(e) {
                    e.preventDefault();
                    const $parent = $(this).closest('li.has-submenu');
                    const isOpen = $parent.hasClass('open');
                    
                    // Close all other submenus
                    $('.sidebar-menu li.has-submenu').removeClass('open');
                    
                    // Toggle current submenu
                    if (!isOpen) {
                        $parent.addClass('open');
                    }
                });
                
                // Show loading modal when clicking on sidebar menu items
                $('.sidebar-menu a').on('click', function(e) {
                    // Don't show loading if clicking the arrow
                    if ($(e.target).closest('.submenu-arrow').length) {
                        return;
                    }
                    $('body').addClass('loading-cursor');
                    const loadingModal = new bootstrap.Modal(document.getElementById('globalLoadingModal'));
                    loadingModal.show();
                });
                
                // Highlight active menu item based on current URL
                const currentPath = window.location.pathname;
                $('.sidebar-menu a').each(function() {
                    const linkPath = $(this).attr('href');
                    if (linkPath && currentPath.includes(linkPath)) {
                        $(this).addClass('active');
                        // If it's a submenu item, open the parent submenu
                        const $submenuItem = $(this).closest('.submenu');
                        if ($submenuItem.length) {
                            $submenuItem.closest('li.has-submenu').addClass('open');
                        }
                    }
                });
            });
        }
        
        initWhenReady();
    });
    </script>

<script defer>
		/*
		 * jQuery UI Autocomplete: Custom HTML in Dropdown
		 * https://salman-w.blogspot.com/2013/12/jquery-ui-autocomplete-examples.html
		 */
		window.addEventListener('DOMContentLoaded', function() {
			if (typeof jQuery !== 'undefined' && jQuery.ui && jQuery.ui.autocomplete) {
				jQuery(function($) {
					// Only initialize autocomplete if the element exists
					if ($("#autocomplete").length) {
				$("#autocomplete").autocomplete({
					delay: 500,
					minLength: 3,
					source: "/search.php",
					position: {
						my : "right top",
						at: "right bottom"
					},
					focus: function(event, ui) {
						// prevent autocomplete from updating the textbox
						event.preventDefault();
					},
					select: function(event, ui) {
						// prevent autocomplete from updating the textbox
						event.preventDefault();
						// navigate to the selected item's url
						//window.open(ui.item.url);
						window.location.href= "/" + ui.item.cat + "/" + ui.item.url;
						//this.value = '';
					}			
					
				}).data("ui-autocomplete")._renderItem = function(ul, item) {
					var $div = $("<div style='width:280px'></div>");
					// $("<ion-icon style='font-size: 12px;padding-right:4px'></ion-icon>").attr("name",item.icon).appendTo($div);//.text(item.desc).appendTo($div);
					$("<span class='m-name'></span>").text(item.desc).appendTo($div);
					return $("<li class='border-bottom'></li>").append($div).appendTo(ul);
				};
			}
		});

    
	</script>


    <script type="text/javascript" charset="utf-8">
			$(document).ready(function() {
        if ($('#EODexportTable').length) {
				  $('#EODexportTable').DataTable({
            "searching": false,
            "lengthChange": false
          });
        }
        if ($('#example').length) {
          $('#example').DataTable({
            "searching": false,
            "lengthChange": false,
            "order": [[ 1, "asc" ]],
            "paging": false
          });
        }
      } );
				});
			}
		});
		</script>

<style>
  .main-layout {
    display: flex;
    min-height: calc(100vh - 56px);
  }
  
  .content-area {
    flex: 1;
    padding: 1rem;
    background-color: #f5f6fa;
  }
  
  .left-sidebar {
    width: 280px;
    background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    padding: 1.5rem 0;
    position: relative;
  }
  
  .sidebar-title {
    font-size: 0.75rem;
    font-weight: 700;
    margin-bottom: 0.75rem;
    color: #95a5a6;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 0 1.5rem;
    position: relative;
  }
  
  .sidebar-title::after {
    content: '';
    position: absolute;
    bottom: -8px;
    left: 1.5rem;
    width: 40px;
    height: 2px;
    background: linear-gradient(90deg, #3498db, transparent);
  }
  
  .sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0 0 2rem 0;
  }
  
  .sidebar-menu li {
    margin-bottom: 0.25rem;
    padding: 0 0.75rem;
  }
  
  .sidebar-menu a {
    display: flex;
    align-items: center;
    padding: 0.75rem 0.75rem;
    color: #ecf0f1;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: 8px;
    position: relative;
    overflow: hidden;
  }
  
  .sidebar-menu a::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    height: 100%;
    width: 3px;
    background: #3498db;
    transform: scaleY(0);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .sidebar-menu a:hover {
    background-color: rgba(52, 152, 219, 0.15);
    color: #ffffff;
    transform: translateX(4px);
    padding-left: 1rem;
  }
  
  .sidebar-menu a:hover::before {
    transform: scaleY(1);
  }
  
  .sidebar-menu a.active {
    background: linear-gradient(90deg, rgba(52, 152, 219, 0.25), rgba(52, 152, 219, 0.1));
    color: #ffffff;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.2);
  }
  
  .sidebar-menu a.active::before {
    transform: scaleY(1);
  }
  
  /* Icon spacing */
  .sidebar-menu a > span:first-child {
    margin-right: 0.75rem;
    font-size: 1.1rem;
    display: inline-flex;
    align-items: center;
    min-width: 24px;
  }
  
  /* Submenu/Dropdown Styles */
  .sidebar-menu li.has-submenu {
    position: relative;
  }
  
  .sidebar-menu .submenu-toggle {
    justify-content: space-between;
  }
  
  .sidebar-menu .submenu-arrow {
    margin-left: auto;
    margin-right: 0;
    font-size: 0.75rem;
    transition: transform 0.3s ease;
    display: inline-flex;
    align-items: center;
  }
  
  .sidebar-menu li.has-submenu.open .submenu-arrow {
    transform: rotate(180deg);
  }
  
  .sidebar-menu .submenu {
    list-style: none;
    padding: 0;
    margin: 0.25rem 0 0 0;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease, padding 0.3s ease;
    padding-left: 0;
  }
  
  .sidebar-menu li.has-submenu.open .submenu {
    max-height: 500px;
    padding: 0.25rem 0;
  }
  
  .sidebar-menu .submenu li {
    margin-bottom: 0;
    padding: 0 0.75rem 0 2.5rem;
  }
  
  .sidebar-menu .submenu a {
    padding: 0.5rem 0.75rem;
    font-size: 0.813rem;
    opacity: 0.85;
  }
  
  .sidebar-menu .submenu a:hover {
    opacity: 1;
    background-color: rgba(52, 152, 219, 0.2);
  }
  
  .sidebar-menu .submenu a.active {
    opacity: 1;
    background: linear-gradient(90deg, rgba(52, 152, 219, 0.3), rgba(52, 152, 219, 0.15));
  }
  
  /* Empty state message */
  .left-sidebar .alert {
    margin: 1.5rem;
    background-color: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #ecf0f1;
    font-size: 0.813rem;
  }
  
  /* Scrollbar styling for sidebar */
  .left-sidebar::-webkit-scrollbar {
    width: 6px;
  }
  
  .left-sidebar::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.1);
  }
  
  .left-sidebar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 3px;
  }
  
  .left-sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.3);
  }
  
  /* Loading cursor */
  body.loading-cursor,
  body.loading-cursor * {
    cursor: wait !important;
  }
  
  /* Modern card styling for content area */
  .content-area .card {
    border: none;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border-radius: 12px;
  }
  
  /* Modern Header Styling */
  .navbar {
    background: linear-gradient(135deg, #212529 0%, #343a40 100%) !important;
    box-shadow: 0 2px 15px rgba(0, 0, 0, 0.25);
    padding: 0.75rem 0;
    border: none;
    position: relative;
  }
  
  .navbar::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: #000000;
    width: 100%;
  }
  
  .navbar .container-fluid {
    padding-left: 1.5rem;
    padding-right: 1.5rem;
  }
  
  .navbar-brand {
    font-size: 1.5rem;
    font-weight: 700;
    color: #ffffff !important;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: transform 0.2s ease;
  }
  
  .navbar-brand:hover {
    transform: scale(1.05);
  }
  
  .navbar-brand .logo-icon {
    font-size: 1.75rem;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
  }
  
  .navbar-brand .logo-text {
    color: #ffffff;
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
  }
  
  .navbar .user-info {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 0.5rem 1rem;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50px;
    backdrop-filter: blur(10px);
  }
  
  .navbar .user-name {
    color: #ffffff;
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  .navbar .user-name i {
    font-size: 1.1rem;
    color: #ffffff;
  }
  
  .navbar .logout-btn {
    color: #ffffff !important;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    padding: 0.5rem 1rem;
    font-weight: 500;
    font-size: 0.875rem;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
  }
  
  .navbar .logout-btn:hover {
    background: rgba(255, 255, 255, 0.25);
    border-color: rgba(255, 255, 255, 0.4);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  }
  
  .navbar .logout-btn i {
    font-size: 1rem;
  }
</style>

 </head>


<body>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/core-js/2.6.10/shim.min.js"></script>
<script lang="javascript" src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>

<nav class="navbar navbar-expand-lg navbar-dark d-print-none">
  <div class="container-fluid">
    
    <a class="navbar-brand" href="/index.php">
      <span class="logo-icon">📊</span>
      <span class="logo-text">Mayesh Analytics</span>
    </a>
    
    <div class="ms-auto">
      <div class="user-info">
        <?php if(isset($_SESSION['username'])): ?>
          <span class="user-name">
            <i class="bi bi-person-fill"></i>
            <?php echo htmlspecialchars($_SESSION['fullName']); ?>
          </span>
        <?php endif; ?>
        <a class="logout-btn" href="/logout.php">
          <i class="bi bi-box-arrow-right"></i>
          <span>Logout</span>
        </a>
      </div>
    </div>
    
  </div>
</nav>

<!--  HEADER END -->

<div class="main-layout">
  <div class="left-sidebar d-print-none">
    <?php
    // Build dynamic menu based on user permissions
    $userEmail = getUserEmail();
    $dashboardModules = getUserModules($userEmail, 'dashboard');
    $reportModules = getUserModules($userEmail, 'report');
    $isAdmin = isAnalyticsAdmin($userEmail);
    
    // Show Dashboards section if user has access to any dashboard
    if (!empty($dashboardModules)) {
    ?>
      <div class="sidebar-title">Dashboards</div>
      <ul class="sidebar-menu">
        <?php foreach ($dashboardModules as $module): ?>
        <?php if ($module['module_code'] === 'petals_data'): ?>
        <!-- Petals Data with Dropdown -->
        <li class="has-submenu">
          <a href="#" class="submenu-toggle">
            <span><?php echo $module['module_icon']; ?></span>
            <span><?php echo htmlspecialchars($module['module_name']); ?></span>
            <span class="submenu-arrow"><i class="bi bi-chevron-down"></i></span>
          </a>
          <ul class="submenu">
            <li>
              <a href="/modules/PetalsData/summary.php">
                <span><i class="bi bi-speedometer2"></i></span>
                <span>Key Metrics Summary</span>
              </a>
            </li>
            <li>
              <a href="/modules/PetalsData/rewards_enrollment.php">
                <span><i class="bi bi-gift"></i></span>
                <span>Rewards Program Enrollment</span>
              </a>
            </li>
            <li>
              <a href="/modules/PetalsData/enrollment_details.php">
                <span><i class="bi bi-person-lines-fill"></i></span>
                <span>Enrollment Details</span>
              </a>
            </li>
            <li>
              <a href="/modules/PetalsData/branch_redemptions.php">
                <span><i class="bi bi-gift-fill"></i></span>
                <span>Branch Redemptions</span>
              </a>
            </li>
            <li>
              <a href="/modules/PetalsData/customer_redemptions.php">
                <span><i class="bi bi-people-fill"></i></span>
                <span>Customer Redemptions</span>
              </a>
            </li>
            <li>
              <a href="/modules/PetalsData/online_orders.php">
                <span><i class="bi bi-cart-check"></i></span>
                <span>Online Orders by Branch</span>
              </a>
            </li>
            <li>
              <a href="/modules/PetalsData/order_summary.php">
                <span><i class="bi bi-clipboard-data"></i></span>
                <span>Order Summary</span>
              </a>
            </li>
          </ul>
        </li>
        <?php else: ?>
        <li>
          <a href="<?php echo htmlspecialchars($module['module_path']); ?>">
            <span><?php echo $module['module_icon']; ?></span>
            <span><?php echo htmlspecialchars($module['module_name']); ?></span>
          </a>
        </li>
        <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    <?php
    }
    
    // Show Reports & Analysis section if user has access to any report
    if (!empty($reportModules)) {
    ?>
      <div class="sidebar-title" style="margin-top: 2rem;">Reports & Analysis</div>
      <ul class="sidebar-menu">
        <?php foreach ($reportModules as $module): ?>
        <li>
          <a href="<?php echo htmlspecialchars($module['module_path']); ?>">
            <span><?php echo $module['module_icon']; ?></span>
            <span><?php echo htmlspecialchars($module['module_name']); ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php
    }
    
    // Show Admin section for admins
    if ($isAdmin) {
    ?>
      <div class="sidebar-title" style="margin-top: 2rem;">Administration</div>
      <ul class="sidebar-menu">
        <li>
          <a href="/admin/manage_permissions.php">
            <span>🔐</span>
            <span>Manage Permissions</span>
          </a>
        </li>
      </ul>
    <?php
    }
    
    // If user has no modules at all, show a message
    if (empty($dashboardModules) && empty($reportModules)) {
    ?>
      <div class="alert alert-warning mt-3">
        <small>No modules available. Please contact your administrator for access.</small>
      </div>
    <?php
    }
    ?>
  </div>
  
  <div class="content-area">
  
  <!-- Global Loading Modal -->
  <div class="modal fade" id="globalLoadingModal" tabindex="-1" aria-labelledby="globalLoadingModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content">
              <div class="modal-body text-center p-4">
                  <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                      <span class="visually-hidden">Loading...</span>
                  </div>
                  <h6 class="mb-0">Loading...</h6>
                  <small class="text-muted">Please wait while we update the data.</small>
              </div>
          </div>
      </div>
  </div>
