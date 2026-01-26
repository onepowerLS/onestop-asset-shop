# Exporting Access Database to CSV

Since we can't directly read .accdb files on the Linux server, we need to export the Access database to CSV files first.

## Option 1: Manual Export (Recommended - Fastest)

1. **Open the Access Database**
   - Double-click `Assets Microsoft Access Database.accdb` in your Downloads folder
   - Or open Microsoft Access and open the file

2. **Export Each Table to CSV**
   - In the left navigation pane, you'll see all tables
   - For each table (especially the main "assets" or similar table):
     - Right-click on the table name
     - Select **Export** → **Text File** (or **Export** → **CSV File**)
     - Choose location: `C:\Users\it\Downloads\access_export\`
     - Click **OK**
     - Make sure "Export data with formatting and layout" is **unchecked**
     - Click **OK**

3. **Upload CSV Files**
   - Once exported, all CSV files will be in `C:\Users\it\Downloads\access_export\`
   - We'll upload these to the server and import them

## Option 2: Using Access Database Engine (If Available)

If you have Microsoft Access Database Engine installed, you can use the PowerShell script:

```powershell
# First, install Access Database Engine if needed:
# Download: https://www.microsoft.com/en-us/download/details.aspx?id=54920

# Then run:
cd C:\Users\it\Downloads
powershell -ExecutionPolicy Bypass -File "path\to\export_access_to_csv.ps1" -AccessDbPath "Assets Microsoft Access Database.accdb"
```

## Option 3: Quick Export Script (If Access is Open)

If you have Access open, you can use this VBA code in the Immediate Window (Ctrl+G):

```vba
Public Sub ExportAllTables()
    Dim tbl As Object
    Dim db As Object
    Set db = CurrentDb
    
    For Each tbl In db.TableDefs
        If Left(tbl.Name, 4) <> "MSys" And Left(tbl.Name, 1) <> "~" Then
            DoCmd.TransferText acExportDelim, , tbl.Name, "C:\Users\it\Downloads\access_export\" & tbl.Name & ".csv", True
            Debug.Print "Exported: " & tbl.Name
        End If
    Next
End Sub
```

## What Tables to Export?

Look for tables like:
- `assets` or `Assets`
- `inventory` or `Inventory`
- `items` or `Items`
- Any table that contains asset/item data

**Once exported, let me know and I'll import the CSV files!**
