#!/usr/bin/env python3
"""
Convert Excel files to CSV
Requires: pip install openpyxl pandas
"""

import os
import sys
import pandas as pd
from pathlib import Path

def convert_excel_to_csv(source_dir, output_dir):
    """Convert all Excel files in source_dir to CSV in output_dir"""
    source_path = Path(source_dir)
    output_path = Path(output_dir)
    output_path.mkdir(parents=True, exist_ok=True)
    
    excel_files = list(source_path.glob("*.xlsx")) + list(source_path.glob("*.xls"))
    
    if not excel_files:
        print(f"No Excel files found in {source_dir}")
        return
    
    print(f"Found {len(excel_files)} Excel files")
    print(f"Output directory: {output_dir}\n")
    
    converted = 0
    for excel_file in excel_files:
        csv_name = excel_file.stem + ".csv"
        csv_path = output_path / csv_name
        
        try:
            print(f"Converting: {excel_file.name} → {csv_name}")
            
            # Read Excel file (first sheet)
            df = pd.read_excel(excel_file, sheet_name=0)
            
            # Save as CSV
            df.to_csv(csv_path, index=False, encoding='utf-8')
            
            converted += 1
            print(f"  ✅ Success ({len(df)} rows)")
            
        except Exception as e:
            print(f"  ❌ Error: {e}")
    
    print(f"\n✅ Converted {converted} / {len(excel_files)} files")
    print(f"CSV files saved to: {output_dir}")

if __name__ == "__main__":
    if len(sys.argv) < 2:
        source_dir = input("Enter source directory: ").strip()
    else:
        source_dir = sys.argv[1]
    
    if len(sys.argv) < 3:
        output_dir = os.path.join(source_dir, "csv")
    else:
        output_dir = sys.argv[2]
    
    convert_excel_to_csv(source_dir, output_dir)
