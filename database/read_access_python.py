#!/usr/bin/env python3
"""
Read Access database (.accdb) using Python
Tries multiple methods to read the database
"""

import sys
import os
import csv

def try_pyodbc(accdb_path):
    """Try using pyodbc with unixODBC"""
    try:
        import pyodbc
        
        # Try different driver names
        drivers = [
            'Microsoft Access Driver (*.mdb, *.accdb)',
            'Microsoft Access Driver (*.mdb)',
        ]
        
        for driver in drivers:
            try:
                conn_str = f'DRIVER={{{driver}}};DBQ={accdb_path};'
                conn = pyodbc.connect(conn_str)
                return conn
            except:
                continue
        
        return None
    except ImportError:
        return None

def try_mdb_tools(accdb_path):
    """Try using mdb-tools command line"""
    import subprocess
    
    try:
        # Check if mdb-tables exists
        result = subprocess.run(['which', 'mdb-tables'], 
                              capture_output=True, text=True)
        if result.returncode != 0:
            return None
        
        # List tables
        result = subprocess.run(['mdb-tables', accdb_path],
                              capture_output=True, text=True)
        if result.returncode == 0:
            return 'mdb-tools'
        return None
    except:
        return None

def export_with_mdb_tools(accdb_path, table_name, output_csv):
    """Export table using mdb-tools"""
    import subprocess
    
    cmd = ['mdb-export', accdb_path, table_name]
    try:
        with open(output_csv, 'w', newline='', encoding='utf-8') as f:
            result = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE, text=True)
            if result.returncode == 0:
                return True
        return False
    except Exception as e:
        print(f"Error: {e}")
        return False

def export_with_pyodbc(conn, table_name, output_csv):
    """Export table using pyodbc connection"""
    import pyodbc
    
    cursor = conn.cursor()
    cursor.execute(f"SELECT * FROM [{table_name}]")
    
    # Get column names
    columns = [column[0] for column in cursor.description]
    
    # Write to CSV
    with open(output_csv, 'w', newline='', encoding='utf-8') as f:
        writer = csv.writer(f)
        writer.writerow(columns)
        
        rows = cursor.fetchall()
        for row in rows:
            writer.writerow(row)
    
    return len(rows)

if __name__ == "__main__":
    accdb_path = sys.argv[1] if len(sys.argv) > 1 else '/tmp/access_import.accdb'
    output_dir = sys.argv[2] if len(sys.argv) > 2 else '/tmp/access_export'
    
    if not os.path.exists(accdb_path):
        print(f"ERROR: Access database not found: {accdb_path}")
        sys.exit(1)
    
    os.makedirs(output_dir, exist_ok=True)
    
    print(f"Reading Access database: {accdb_path}")
    print(f"Output directory: {output_dir}\n")
    
    # Try mdb-tools first (simpler)
    method = try_mdb_tools(accdb_path)
    if method == 'mdb-tools':
        print("✅ Using mdb-tools")
        
        # Get table list
        import subprocess
        result = subprocess.run(['mdb-tables', accdb_path],
                              capture_output=True, text=True)
        tables = [t.strip() for t in result.stdout.strip().split() if t.strip()]
        
        print(f"Found tables: {', '.join(tables)}\n")
        
        for table in tables:
            if table.startswith('MSys') or table.startswith('~'):
                continue
            
            csv_path = os.path.join(output_dir, f"{table}.csv")
            print(f"Exporting: {table} → {csv_path}")
            
            if export_with_mdb_tools(accdb_path, table, csv_path):
                row_count = len(open(csv_path).readlines()) - 1
                print(f"  ✅ Exported {row_count} rows")
            else:
                print(f"  ❌ Failed to export")
    
    # Try pyodbc
    elif try_pyodbc(accdb_path):
        print("✅ Using pyodbc")
        conn = try_pyodbc(accdb_path)
        
        # Get tables
        cursor = conn.cursor()
        tables = [row.table_name for row in cursor.tables(tableType='TABLE') 
                 if not row.table_name.startswith('MSys')]
        
        print(f"Found tables: {', '.join(tables)}\n")
        
        for table in tables:
            csv_path = os.path.join(output_dir, f"{table}.csv")
            print(f"Exporting: {table} → {csv_path}")
            
            try:
                row_count = export_with_pyodbc(conn, table, csv_path)
                print(f"  ✅ Exported {row_count} rows")
            except Exception as e:
                print(f"  ❌ Error: {e}")
        
        conn.close()
    
    else:
        print("❌ No method available to read Access database")
        print("\nTried:")
        print("  - mdb-tools (not installed)")
        print("  - pyodbc (not available or no driver)")
        print("\nOptions:")
        print("1. Install mdbtools: sudo dnf install mdbtools (if available)")
        print("2. Build mdbtools from source")
        print("3. Use online converter or different machine with Access")
        sys.exit(1)
    
    print(f"\n✅ Export complete! CSV files in: {output_dir}")
