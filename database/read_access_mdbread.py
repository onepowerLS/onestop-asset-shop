#!/usr/bin/env python3
"""
Read Access database using mdbread library
Works with both .mdb and .accdb files
"""

import sys
import os
import csv

try:
    import mdbread
    HAS_MDBREAD = True
except ImportError:
    HAS_MDBREAD = False

def export_with_mdbread(accdb_path, output_dir):
    """Export Access database using mdbread"""
    if not HAS_MDBREAD:
        print("ERROR: mdbread not installed")
        print("Install: pip3 install --user mdbread")
        return False
    
    try:
        # Open database
        db = mdbread.MDB(accdb_path)
        
        print(f"Opened database: {accdb_path}")
        print(f"Tables: {list(db.keys())}\n")
        
        exported = 0
        for table_name in db.keys():
            if table_name.startswith('MSys') or table_name.startswith('~'):
                continue
            
            csv_path = os.path.join(output_dir, f"{table_name}.csv")
            print(f"Exporting: {table_name} → {csv_path}")
            
            try:
                # Get table data
                table = db[table_name]
                
                # Write to CSV
                with open(csv_path, 'w', newline='', encoding='utf-8') as f:
                    writer = csv.writer(f)
                    
                    # Write header
                    if len(table) > 0:
                        writer.writerow(table[0].keys())
                    
                    # Write rows
                    for row in table:
                        writer.writerow(row.values())
                
                row_count = len(table)
                print(f"  ✅ Exported {row_count} rows")
                exported += 1
                
            except Exception as e:
                print(f"  ❌ Error: {e}")
        
        db.close()
        
        print(f"\n✅ Export complete! Exported {exported} tables")
        return True
        
    except Exception as e:
        print(f"ERROR: Cannot read Access database: {e}")
        return False

if __name__ == "__main__":
    accdb_path = sys.argv[1] if len(sys.argv) > 1 else '/tmp/access_import.accdb'
    output_dir = sys.argv[2] if len(sys.argv) > 2 else '/tmp/access_export'
    
    if not os.path.exists(accdb_path):
        print(f"ERROR: Access database not found: {accdb_path}")
        sys.exit(1)
    
    os.makedirs(output_dir, exist_ok=True)
    
    if export_with_mdbread(accdb_path, output_dir):
        print(f"\nCSV files saved to: {output_dir}")
    else:
        sys.exit(1)
