#!/bin/bash
# Export Access database tables to CSV

ACCDB_FILE="/tmp/access_import.accdb"
OUTPUT_DIR="/tmp/access_export"

mkdir -p "$OUTPUT_DIR"

# Get table list (suppress warnings)
TABLES=$(/usr/local/bin/mdb-tables "$ACCDB_FILE" 2>/dev/null | grep -v Warning | tr ' ' '\n' | grep -v '^$')

echo "Found tables: $TABLES"
echo ""

for table in $TABLES; do
    echo "Exporting: $table"
    /usr/local/bin/mdb-export "$ACCDB_FILE" "$table" 2>/dev/null > "$OUTPUT_DIR/$table.csv"
    if [ $? -eq 0 ]; then
        ROWS=$(wc -l < "$OUTPUT_DIR/$table.csv")
        echo "  ✅ Exported $ROWS rows"
    else
        echo "  ❌ Failed"
    fi
done

echo ""
echo "Export complete! Files in: $OUTPUT_DIR"
ls -lh "$OUTPUT_DIR"
