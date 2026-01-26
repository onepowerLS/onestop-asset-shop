# PowerShell script to convert Excel files to CSV
# Requires: Excel COM object (works on Windows with Excel installed)

$sourceDir = "$env:USERPROFILE\Downloads\10_AM_spreadsheets"
$outputDir = "$env:USERPROFILE\Downloads\10_AM_spreadsheets\csv"

if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

Write-Host "=== Converting Excel Files to CSV ===" -ForegroundColor Green
Write-Host "Source: $sourceDir"
Write-Host "Output: $outputDir`n"

$excelFiles = Get-ChildItem -Path $sourceDir -Filter "*.xlsx"

if ($excelFiles.Count -eq 0) {
    Write-Host "No Excel files found!" -ForegroundColor Red
    exit
}

# Try to use Excel COM object
try {
    $excel = New-Object -ComObject Excel.Application
    $excel.Visible = $false
    $excel.DisplayAlerts = $false
    
    $converted = 0
    foreach ($file in $excelFiles) {
        $csvName = [System.IO.Path]::GetFileNameWithoutExtension($file.Name) + ".csv"
        $csvPath = Join-Path $outputDir $csvName
        
        Write-Host "Converting: $($file.Name) → $csvName" -ForegroundColor Yellow
        
        try {
            $workbook = $excel.Workbooks.Open($file.FullName)
            $worksheet = $workbook.Worksheets.Item(1)
            
            # Save as CSV
            $workbook.SaveAs($csvPath, 6) # 6 = CSV format
            $workbook.Close($false)
            
            $converted++
            Write-Host "  ✅ Converted successfully" -ForegroundColor Green
        } catch {
            Write-Host "  ❌ Error: $_" -ForegroundColor Red
        }
    }
    
    $excel.Quit()
    [System.Runtime.Interopservices.Marshal]::ReleaseComObject($excel) | Out-Null
    
    Write-Host "`n=== Conversion Complete ===" -ForegroundColor Green
    Write-Host "Converted: $converted / $($excelFiles.Count) files"
    Write-Host "CSV files saved to: $outputDir"
    
} catch {
    Write-Host "Excel COM object not available. Alternative methods:" -ForegroundColor Yellow
    Write-Host "1. Open each file in Excel and save as CSV manually"
    Write-Host "2. Use LibreOffice: libreoffice --headless --convert-to csv --outdir `"$outputDir`" `"$sourceDir\*.xlsx`""
    Write-Host "3. Use online converter"
}
