# Accessing Your Google Drive Folder

## Folder Link
https://drive.google.com/drive/folders/152_3tRqi8Il_z_E7WfR-l1nonPv5JYib

## Steps to Access

1. **Open the link** in your browser
   - You may need to sign in with a Google account

2. **Once in the folder**, you should see all your Google Sheets:
   - RET Material Items Database
   - FAC Material Items Database
   - O&M Material Database
   - Meters_Meter Enclosures_Ready Boards Database
   - General Materials and Items Database
   - Engineering Tool List
   - Powerhouse parts list_Lichaba
   - etc.

3. **To find the owner:**
   - Right-click on any sheet → "Share" or "Get link"
   - Or click on a sheet to open it, then click "Share" button
   - The owner will be listed in the sharing dialog

4. **To get Sheet IDs:**
   - Click on a sheet to open it
   - Look at the URL in your browser
   - The URL will be: `https://docs.google.com/spreadsheets/d/{SHEET_ID}/edit`
   - Copy the Sheet ID (the long string between `/d/` and `/edit`)

## What Account to Use

- **If you can access the folder**, use that Google account for Google Cloud setup
- **If you can't access it**, note which account you tried, and we'll need to:
  - Get access to that account, OR
  - Have the owner set up the service account, OR
  - Export sheets manually to CSV

## Next Steps

Once you can access the folder:
1. Tell me which Google account you used to access it
2. Open one sheet and check the "Share" button to see the owner
3. Get the Sheet IDs from the URLs
4. Then we'll proceed with Google Cloud setup using the correct account
