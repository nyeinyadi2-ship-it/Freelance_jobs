$baseUrl = "http://localhost/freelancer_job"
$loginUrl = "$baseUrl/auth/login.php"

# Function to login and get a session
function Get-LoginSession($email, $password) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    # GET login page to get CSRF token
    $res1 = Invoke-WebRequest -Uri $loginUrl -WebSession $session
    $csrfToken = ($res1.ParsedHtml.getElementsByName("csrf_token") | Select-Object -First 1).value
    
    # POST login
    $body = @{
        email = $email
        password = $password
        csrf_token = $csrfToken
    }
    $res2 = Invoke-WebRequest -Uri $loginUrl -Method Post -Body $body -WebSession $session
    return $session
}

# Assume password is 'password123' since we already updated it
$flSession = Get-LoginSession "nyein@gmail.com" "password123"
Invoke-WebRequest -Uri "$baseUrl/freelancer/browse_jobs.php" -WebSession $flSession | Out-Null
Invoke-WebRequest -Uri "$baseUrl/freelancer/browse_jobs.php?skill=PHP" -WebSession $flSession | Out-Null
Invoke-WebRequest -Uri "$baseUrl/freelancer/view_job.php?id=1" -WebSession $flSession | Out-Null
Invoke-WebRequest -Uri "$baseUrl/index.php" -WebSession $flSession | Out-Null
Invoke-WebRequest -Uri "$baseUrl/index.php" -WebSession $flSession | Out-Null

$coSession = Get-LoginSession "dell@company.com" "password123"
Invoke-WebRequest -Uri "$baseUrl/company/manage_jobs.php" -WebSession $coSession | Out-Null
Invoke-WebRequest -Uri "$baseUrl/company/view_job.php?id=1" -WebSession $coSession | Out-Null
Invoke-WebRequest -Uri "$baseUrl/company/find_freelancers.php" -WebSession $coSession | Out-Null
Invoke-WebRequest -Uri "$baseUrl/company/view_freelancer.php?id=1" -WebSession $coSession | Out-Null
