#!/usr/bin/env python3
"""
Export Access database to CSV using pyodbc
Requires: pip install pyodbc
And: Microsoft Access Database Engine (ACE)
"""

import sys
import os
import csv
import pyodbc

def export_access_to_csv(accdb_path, output_dir):
    """Export all tables from Access database to CSV"""
    
    if not os.path.exists(accdb_path):
        print(f"ERROR: Access database not found: {accdb_path}")
        return False
    
    # Create output directory
    os.makedirs(output_dir, exist_ok=True)
    
    # Try different Access driver names
    drivers = [
        'Microsoft Access Driver (*.mdb, *.accdb)',
        'Microsoft Access Driver (*.mdb)',
        'Driver do Microsoft Access (*.mdb)',
    ]
    
    driver = None
    for d in drivers:
        if d in pyodbc.drivers():
            driver = d
            break
    
    if not driver:
        print("ERROR: Microsoft Access Database Engine not found")
        print("Please install: https://www.microsoft.com/en-us/download/details.aspx?id=54920")
        print("\nAvailable drivers:")
        for d in pyodbc.drivers():
            print(f"  - {d}")
        return False
    
    print(f"Using driver: {driver}")
    
    # Connect to Access database
    conn_str = f'DRIVER={{{driver}}};DBQ={accdb_path};'
    
    try:
        conn = pyodbc.connect(conn_str)
        cursor = conn.cursor()
        
        # Get all table names
        tables = []
        for table_info in cursor.tables(tableType='TABLE'):
            table_name = table_info.table_name
            if not table_name.startswith('MSys') and not table_name.startswith('~'):
                tables.append(table_name)
        
        print(f"\nFound {len(tables)} tables: {', '.join(tables)}")
        
        exported = 0
        for table in tables:
            csv_path = os.path.join(output_dir, f"{table}.csv")
            print(f"\nExporting: {table} → {csv_path}")
            
            try:
                # Get all data from table
                cursor.execute(f"SELECT * FROM [{table}]")
                rows = cursor.fetchall()
                
                if not rows:
                    print(f"  ⚠️  Table is empty")
                    continue
                
                # Get column names
                columns = [column[0] for column in cursor.description]
                
                # Write to CSV
                with open(csv_path, 'w', newline='', encoding='utf-8') as f:
                    writer = csv.writer(f)
                    writer.writerow(columns)
                    writer.writerows(rows)
                
                print(f"  ✅ Exported {len(rows)} rows")
                exported += 1
                
            except Exception as e:
                print(f"  ❌ Error: {e}")
        
        conn.close()
        
        print(f"\n=== Export Complete ===")
        print(f"Exported {exported} / {len(tables)} tables")
        print(f"CSV files saved to: {output_dir}")
        return True
        
    except Exception as e:
        print(f"ERROR: Cannot connect to Access database: {e}")
        return False

if __name__ == "__main__":
    if len(sys.argv) < 2:
        accdb_path = input("Enter path to Access database (.accdb): ").strip()
    else:
        accdb_path = sys.argv[1]
    
    if len(sys.argv) < 3:
        output_dir = os.path.join(os.path.dirname(accdb_path), "access_export")
    else:
        output_dir = sys.argv[2]
    
    export_access_to_csv(accdb_path, output_dir)
