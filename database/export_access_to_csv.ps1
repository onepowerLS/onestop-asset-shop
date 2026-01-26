# PowerShell script to export Access database to CSV
# Requires: Microsoft Access or Access Database Engine

param(
    [Parameter(Mandatory=$true)]
    [string]$AccessDbPath,
    
    [Parameter(Mandatory=$false)]
    [string]$OutputDir = "$env:USERPROFILE\Downloads\access_export"
)

Write-Host "=== Exporting Access Database to CSV ===" -ForegroundColor Green
Write-Host "Database: $AccessDbPath"
Write-Host "Output: $OutputDir`n"

if (-not (Test-Path $AccessDbPath)) {
    Write-Host "ERROR: Access database not found: $AccessDbPath" -ForegroundColor Red
    exit 1
}

# Create output directory
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir | Out-Null
}

# Try to use Access COM object
try {
    $access = New-Object -ComObject Access.Application
    $access.Visible = $false
    $access.OpenCurrentDatabase($AccessDbPath)
    
    Write-Host "Connected to Access database" -ForegroundColor Green
    
    # Get all table names
    $tables = @()
    foreach ($obj in $access.CurrentDb.TableDefs) {
        if (-not $obj.Name.StartsWith("MSys") -and -not $obj.Name.StartsWith("~")) {
            $tables += $obj.Name
        }
    }
    
    Write-Host "`nFound tables: $($tables -join ', ')" -ForegroundColor Yellow
    
    $exported = 0
    foreach ($table in $tables) {
        $csvPath = Join-Path $OutputDir "$table.csv"
        Write-Host "Exporting: $table → $csvPath" -ForegroundColor Yellow
        
        try {
            # Export using DoCmd.TransferText
            $access.DoCmd.TransferText(
                [Microsoft.Office.Interop.Access.AcTransferType]::acExportDelim,
                "",  # Specification name (empty = default)
                $table,
                $csvPath,
                $true  # Has field names
            )
            
            $exported++
            Write-Host "  ✅ Exported successfully" -ForegroundColor Green
        } catch {
            Write-Host "  ❌ Error: $_" -ForegroundColor Red
        }
    }
    
    $access.CloseCurrentDatabase()
    $access.Quit()
    [System.Runtime.Interopservices.Marshal]::ReleaseComObject($access) | Out-Null
    
    Write-Host "`n=== Export Complete ===" -ForegroundColor Green
    Write-Host "Exported $exported / $($tables.Count) tables"
    Write-Host "CSV files saved to: $OutputDir"
    
} catch {
    Write-Host "ERROR: Cannot access Access database" -ForegroundColor Red
    Write-Host "Error: $_" -ForegroundColor Red
    Write-Host "`nAlternative: Install Microsoft Access Database Engine:" -ForegroundColor Yellow
    Write-Host "https://www.microsoft.com/en-us/download/details.aspx?id=54920" -ForegroundColor Yellow
    Write-Host "`nOr use manual export:" -ForegroundColor Yellow
    Write-Host "1. Open Access database"
    Write-Host "2. For each table: Right-click → Export → Text File"
    Write-Host "3. Choose CSV format"
    exit 1
}
