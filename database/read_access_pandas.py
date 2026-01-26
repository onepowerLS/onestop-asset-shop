#!/usr/bin/env python3
"""
Read Access database using pandas-access
Works with .mdb files, may work with .accdb
"""

import sys
import os
import csv

# Add user site-packages to path
user_site = os.path.expanduser('~/.local/lib/python3.9/site-packages')
if os.path.exists(user_site) and user_site not in sys.path:
    sys.path.insert(0, user_site)

try:
    import pandas_access as mdb
    HAS_PANDAS_ACCESS = True
except ImportError as e:
    HAS_PANDAS_ACCESS = False
    print(f"Import error: {e}", file=sys.stderr)

def export_with_pandas_access(accdb_path, output_dir):
    """Export Access database using pandas-access"""
    if not HAS_PANDAS_ACCESS:
        print("ERROR: pandas-access not installed")
        print("Install: pip3 install --user pandas-access")
        print("Note: Also requires mdbtools system package")
        return False
    
    try:
        print(f"Opening database: {accdb_path}")
        
        # List tables
        try:
            tables = mdb.list_tables(accdb_path)
            print(f"Found tables: {', '.join(tables)}\n")
        except Exception as e:
            print(f"ERROR listing tables: {e}")
            print("\nNote: pandas-access primarily supports .mdb files.")
            print("For .accdb files, you may need to:")
            print("1. Convert .accdb to .mdb using Access (if available)")
            print("2. Or use a different method")
            return False
        
        exported = 0
        for table_name in tables:
            if table_name.startswith('MSys') or table_name.startswith('~'):
                continue
            
            csv_path = os.path.join(output_dir, f"{table_name}.csv")
            print(f"Exporting: {table_name} → {csv_path}")
            
            try:
                # Read table
                df = mdb.read_table(accdb_path, table_name)
                
                # Write to CSV
                df.to_csv(csv_path, index=False, encoding='utf-8')
                
                row_count = len(df)
                print(f"  ✅ Exported {row_count} rows")
                exported += 1
                
            except Exception as e:
                print(f"  ❌ Error: {e}")
        
        print(f"\n✅ Export complete! Exported {exported} tables")
        return True
        
    except Exception as e:
        print(f"ERROR: Cannot read Access database: {e}")
        print("\nThis may be because:")
        print("1. pandas-access requires mdbtools system package")
        print("2. .accdb files may not be fully supported")
        print("3. Database may be encrypted or corrupted")
        return False

if __name__ == "__main__":
    accdb_path = sys.argv[1] if len(sys.argv) > 1 else '/tmp/access_import.accdb'
    output_dir = sys.argv[2] if len(sys.argv) > 2 else '/tmp/access_export'
    
    if not os.path.exists(accdb_path):
        print(f"ERROR: Access database not found: {accdb_path}")
        sys.exit(1)
    
    os.makedirs(output_dir, exist_ok=True)
    
    if export_with_pandas_access(accdb_path, output_dir):
        print(f"\nCSV files saved to: {output_dir}")
    else:
        sys.exit(1)
