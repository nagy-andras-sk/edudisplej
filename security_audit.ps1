#!/usr/bin/env pwsh

# PHP Security Audit Script for EduDisplej
# Examines PHP files for security issues

$auditResults = @()

function Test-PhpSecurity {
    param(
        [string]$FilePath,
        [string]$Category
    )
    
    $fileName = Split-Path $FilePath -Leaf
    $content = Get-Content -Path $FilePath -Raw -Encoding UTF8 -ErrorAction SilentlyContinue
    
    if (-not $content) {
        return $null
    }
    
    $findings = @{
        FileName = $fileName
        FilePath = $FilePath
        Category = $Category
        SessionCheck = $false
        LoginCheck = $false
        AdminCheck = $false
        PreparedStatements = $false
        XSSProtection = $false
        CSRFProtection = $false
        CompanyIsolation = $false
        PasswordHashing = $false
        RateLimiting = $false
        Encryption = $false
        Issues = @()
        FileSize = (Get-Item $FilePath).Length
        LineCount = ($content -split '\n').Count
    }
    
    # Check for session_start
    if ($content -match 'session_start\s*\(' ) {
        $findings.SessionCheck = $true
    } else {
        $findings.Issues += "Hiányzó session_start()"
    }
    
    # Check for login checks
    if ($content -match '\$_SESSION\[.*user_id' -or $content -match 'isset\(\$_SESSION\[.*user') {
        $findings.LoginCheck = $true
    } else {
        if ($fileName -notmatch '^(login|registration|auth|health|check)' -and $Category -ne "api") {
            $findings.Issues += "Nincsen user authentikáció ellenőrzése"
        }
    }
    
    # Check for admin check
    if ($content -match 'isadmin' -or $content -match 'admin_user' -or $content -match 'is_admin') {
        $findings.AdminCheck = $true
    }
    
    # Check for prepared statements
    if ($content -match '\$conn->prepare' -or $content -match '\$stmt->bind_param' -or $content -match 'mysqli_prepare') {
        $findings.PreparedStatements = $true
    } else {
        if ($content -match 'SELECT|INSERT|UPDATE|DELETE' -and -not ($content -match '\$\{' -or $content -match '\$_(GET|POST)\[')) {
            # Only report if there are actual SQL queries
            $findings.Issues += "SQL injection veszély: nincs prepared statement"
        }
    }
    
    # Check for XSS protection (htmlspecialchars, htmlentities, etc.)
    if ($content -match 'htmlspecialchars|htmlentities|json_encode' -or $content -match 'header.*json') {
        $findings.XSSProtection = $true
    } else {
        if ($content -match 'echo\s+\$_' -or $content -match 'echo\s+\$[a-zA-Z]' -and -not ($content -match 'json_encode')) {
            $findings.Issues += "Lehetséges XSS veszély: felhasználói adat közvetlen kiírása"
        }
    }
    
    # Check for CSRF tokens
    if ($content -match '_token|csrf|nonce|X-CSRF|validate_request_signature') {
        $findings.CSRFProtection = $true
    }
    
    # Check for company isolation
    if ($content -match 'company_id.*_SESSION|WHERE.*company_id|api_require_company' -or $content -match 'api_require_group_company') {
        $findings.CompanyIsolation = $true
    } else {
        if ($content -match 'SELECT|UPDATE|DELETE' -and $fileName -notmatch '^(health|status|auth|health)') {
            $findings.Issues += "Lehetséges szervezeti adatok lecsökkentsítése: nincs company_id szűrés"
        }
    }
    
    # Check for password hashing
    if ($content -match 'password_hash|PASSWORD_DEFAULT|hash_password|bcrypt') {
        $findings.PasswordHashing = $true
    } else {
        if ($content -match 'password.*INSERT|password.*UPDATE|md5.*password' -or $content -match '\$_POST.*password') {
            $findings.Issues += "Jelszó hashing nincs használva vagy gyenge hashing"
        }
    }
    
    # Check for rate limiting
    if ($content -match 'rate_limit|throttle|sleep|usleep|attempt' -and -not ($content -match 'sync_interval')) {
        $findings.RateLimiting = $true
    }
    
    # Check for encryption
    if ($content -match 'openssl|mcrypt|encryption|encrypt|decrypt|hash_hmac|hmac' -or $content -match 'hash_equals') {
        $findings.Encryption = $true
    }
    
    # Check file size
    if ($findings.FileSize -gt 500000) {
        $findings.Issues += "Nagyon nagy fájl: $($findings.FileSize) bájt"
    } elseif ($findings.FileSize -gt 100000) {
        $findings.Issues += "Nagy fájl: $($findings.FileSize) bájt"
    }
    
    return $findings
}

# Scan API folder
$apiFolder = "webserver\control_edudisplej_sk\api"
$apiFiles = Get-ChildItem -Path $apiFolder -Filter "*.php" -File

Write-Host "Vizsgálom az API fájlokat..."
foreach ($file in $apiFiles) {
    $finding = Test-PhpSecurity -FilePath $file.FullName -Category "api"
    if ($finding) {
        $auditResults += $finding
    }
}

# Scan Admin folder
$adminFolder = "webserver\control_edudisplej_sk\admin"
$adminFiles = Get-ChildItem -Path $adminFolder -Filter "*.php" -File

Write-Host "Vizsgálom az Admin fájlokat..."
foreach ($file in $adminFiles) {
    $finding = Test-PhpSecurity -FilePath $file.FullName -Category "admin"
    if ($finding) {
        $auditResults += $finding
    }
}

# Scan Dashboard folder
$dashboardFolder = "webserver\control_edudisplej_sk\dashboard"
$dashboardFiles = Get-ChildItem -Path $dashboardFolder -Filter "*.php" -File -Recurse

Write-Host "Vizsgálom a Dashboard fájlokat..."
foreach ($file in $dashboardFiles) {
    $finding = Test-PhpSecurity -FilePath $file.FullName -Category "dashboard"
    if ($finding) {
        $auditResults += $finding
    }
}

# Generate audit report
$reportContent = @"
╔════════════════════════════════════════════════════════════════════════════════╗
║         EDUDISPLEJ - BIZTONSÁGI AUDIT JELENTÉS                                  ║
║         Dátum: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")                        ║
╚════════════════════════════════════════════════════════════════════════════════╝

VIZSGÁLT MAPPÁK:
  • webserver/control_edudisplej_sk/api/ ($($apiFiles.Count) fájl)
  • webserver/control_edudisplej_sk/admin/ ($($adminFiles.Count) fájl)
  • webserver/control_edudisplej_sk/dashboard/ ($($dashboardFiles.Count) fájl)

ÖSSZESEN VIZSGÁLT FÁJLOK: $($auditResults.Count)

═════════════════════════════════════════════════════════════════════════════════

"@

# Categorize results
$securitySummary = @{
    HIGH = @()
    MEDIUM = @()
    LOW = @()
}

foreach ($result in $auditResults | Sort-Object -Property Category, FileName) {
    $securityLevel = "HIGH"
    
    # Determine security level
    $positiveCount = 0
    $negativeCount = 0
    
    if ($result.SessionCheck) { $positiveCount++ } else { $negativeCount++ }
    if ($result.PreparedStatements) { $positiveCount++ } else { $negativeCount++ }
    if ($result.XSSProtection) { $positiveCount++ } else { $negativeCount++ }
    if ($result.CompanyIsolation) { $positiveCount++ } else { $negativeCount++ }
    if ($result.PasswordHashing) { $positiveCount++ } else { $negativeCount++ }
    
    if ($result.Issues.Count -eq 0 -and $positiveCount -ge 4) {
        $securityLevel = "HIGH"
    } elseif ($result.Issues.Count -le 2 -and $positiveCount -ge 3) {
        $securityLevel = "MEDIUM"
    } else {
        $securityLevel = "LOW"
    }
    
    # Add to security summary
    $securitySummary[$securityLevel] += $result
}

# Write summary by security level
$reportContent += "BIZTONSÁGI SZINT SZERINTI ÖSSZEFOGLALÁS:`n"
$reportContent += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`n"
$reportContent += "`n✓ MAGAS BIZTONSÁG (HIGH) - $($securitySummary.HIGH.Count) fájl`n"
$reportContent += "✓ KÖZEPES BIZTONSÁG (MEDIUM) - $($securitySummary.MEDIUM.Count) fájl`n"
$reportContent += "✗ ALACSONY BIZTONSÁG (LOW) - $($securitySummary.LOW.Count) fájl`n`n"

# Detailed report
$reportContent += "═════════════════════════════════════════════════════════════════════════════════`n"
$reportContent += "RÉSZLETES FÁJLELEMZÉS - API VÉGPONTOK`n"
$reportContent += "═════════════════════════════════════════════════════════════════════════════════`n"

foreach ($result in $auditResults | Where-Object { $_.Category -eq "api" } | Sort-Object FileName) {
    $securityStatus = if ($securitySummary.HIGH -contains $result) { "✓ MAGAS" } elseif ($securitySummary.MEDIUM -contains $result) { "✓ KÖZEPES" } else { "✗ ALACSONY" }
    
    $reportContent += "`n[API] $($result.FileName)`n"
    $reportContent += "  Status: $securityStatus`n"
    $reportContent += "  Méret: $($result.FileSize) bájt | Sorok: $($result.LineCount)`n"
    $reportContent += "  Authentikáció: $(if($result.SessionCheck){'✓ Van`n'}else{'✗ Nincs`n'})"
    $reportContent += "  Jogosultság: $(if($result.AdminCheck){'✓ Van`n'}else{'✗ Nincs`n'})"
    $reportContent += "  SQL Protection: $(if($result.PreparedStatements){'✓ Prepared statements`n'}else{'✗ Hiányzik`n'})"
    $reportContent += "  XSS Protection: $(if($result.XSSProtection){'✓ Sanitizálva`n'}else{'✗ Hiányzik`n'})"
    $reportContent += "  CSRF Protection: $(if($result.CSRFProtection){'✓ Van`n'}else{'✗ Nincs`n'})"
    $reportContent += "  Company Isolation: $(if($result.CompanyIsolation){'✓ Van`n'}else{'✗ Nincs`n'})"
    
    if ($result.Issues.Count -gt 0) {
        $reportContent += "  ⚠️  Problémák:`n"
        foreach ($issue in $result.Issues) {
            $reportContent += "    - $issue`n"
        }
    }
}

$reportContent += "`n`n"
$reportContent += "═════════════════════════════════════════════════════════════════════════════════`n"
$reportContent += "RÉSZLETES FÁJLELEMZÉS - ADMIN OLDAL`n"
$reportContent += "═════════════════════════════════════════════════════════════════════════════════`n"

foreach ($result in $auditResults | Where-Object { $_.Category -eq "admin" } | Sort-Object FileName) {
    $securityStatus = if ($securitySummary.HIGH -contains $result) { "✓ MAGAS" } elseif ($securitySummary.MEDIUM -contains $result) { "✓ KÖZEPES" } else { "✗ ALACSONY" }
    
    $reportContent += "`n[ADMIN] $($result.FileName)`n"
    $reportContent += "  Status: $securityStatus`n"
    $reportContent += "  Méret: $($result.FileSize) bájt | Sorok: $($result.LineCount)`n"
    $reportContent += "  Session Check: $(if($result.SessionCheck){'✓ Van`n'}else{'✗ Nincs`n'})"
    $reportContent += "  Admin Check: $(if($result.AdminCheck){'✓ Van`n'}else{'✗ Nincs`n'})"
    $reportContent += "  SQL Protection: $(if($result.PreparedStatements){'✓ Prepared statements`n'}else{'✗ Hiányzik`n'})"
    
    if ($result.Issues.Count -gt 0) {
        $reportContent += "  ⚠️  Problémák:`n"
        foreach ($issue in $result.Issues) {
            $reportContent += "    - $issue`n"
        }
    }
}

$reportContent += "`n`n"
$reportContent += "═════════════════════════════════════════════════════════════════════════════════`n"
$reportContent += "RÉSZLETES FÁJLELEMZÉS - DASHBOARD`n"
$reportContent += "═════════════════════════════════════════════════════════════════════════════════`n"

foreach ($result in $auditResults | Where-Object { $_.Category -eq "dashboard" } | Sort-Object FileName) {
    $securityStatus = if ($securitySummary.HIGH -contains $result) { "✓ MAGAS" } elseif ($securitySummary.MEDIUM -contains $result) { "✓ KÖZEPES" } else { "✗ ALACSONY" }
    
    $reportContent += "`n[DASHBOARD] $($result.FileName)`n"
    $reportContent += "  Status: $securityStatus`n"
    $reportContent += "  Méret: $($result.FileSize) bájt | Sorok: $($result.LineCount)`n"
    $reportContent += "  Session Check: $(if($result.SessionCheck){'✓ Van`n'}else{'✗ Nincs`n'})"
    $reportContent += "  Login Check: $(if($result.LoginCheck){'✓ Van`n'}else{'✗ Nincs`n'})"
    
    if ($result.Issues.Count -gt 0) {
        $reportContent += "  ⚠️  Problémák:`n"
        foreach ($issue in $result.Issues) {
            $reportContent += "    - $issue`n"
        }
    }
}

# Write report to file
$reportContent | Out-File -FilePath "audit.txt" -Encoding UTF8

Write-Host "✓ Audit jelentés elkészült: audit.txt"
