/**
 * Vehicle Integration: Fetch vehicles from AM (Asset Management) system
 * 
 * AM is the single source of truth for vehicles.
 * This service fetches vehicle data from the AM API and transforms it
 * for use in the PR system's reference data.
 * 
 * USAGE in PR system:
 * 1. Copy this file to: src/services/amIntegration.ts
 * 2. Update src/services/referenceData.ts to call fetchVehiclesFromAM()
 * 3. Remove Vehicle.csv dependency
 */

const AM_API_BASE = 'https://am.1pwrafrica.com/api';

interface AMVehicle {
  id: number;
  code: string;
  name: string;
  vinNumber: string | null;
  make: string | null;
  model: string | null;
  registrationNumber: string | null;
  status: string;
  isActive: number;
  organization: string;
  location: string | null;
}

interface AMVehiclesResponse {
  success: boolean;
  count: number;
  vehicles: AMVehicle[];
  source: string;
  timestamp: string;
}

interface PRVehicle {
  id: string;
  code: string;
  name: string;
  registrationNumber?: string;
  year?: number;
  make?: string;
  model?: string;
  vinNumber?: string;
  engineNumber?: string;
  isActive: boolean;
  organization?: {
    id: string;
    name: string;
  };
  organizationId?: string;
}

/**
 * Fetch vehicles from AM system and transform to PR format
 */
export async function fetchVehiclesFromAM(): Promise<PRVehicle[]> {
  try {
    const response = await fetch(`${AM_API_BASE}/vehicles/`);
    
    if (!response.ok) {
      throw new Error(`AM API error: ${response.status} ${response.statusText}`);
    }
    
    const data: AMVehiclesResponse = await response.json();
    
    if (!data.success) {
      throw new Error('AM API returned unsuccessful response');
    }
    
    console.log(`[AM Integration] Fetched ${data.count} vehicles from AM (${data.timestamp})`);
    
    // Transform AM vehicles to PR format
    return data.vehicles.map((v): PRVehicle => ({
      id: `am-${v.id}`, // Prefix with 'am-' to identify source
      code: v.code,
      name: v.name,
      registrationNumber: v.registrationNumber || undefined,
      make: v.make || undefined,
      model: v.model || undefined,
      vinNumber: v.vinNumber || undefined,
      isActive: v.isActive === 1 && v.status === 'Available',
      // Map organization code to PR organization
      organizationId: mapOrganizationCode(v.organization),
    }));
    
  } catch (error) {
    console.error('[AM Integration] Failed to fetch vehicles:', error);
    // Return empty array on error - PR system should handle gracefully
    return [];
  }
}

/**
 * Map AM country code to PR organization ID
 */
function mapOrganizationCode(code: string): string {
  const orgMap: Record<string, string> = {
    'LSO': '1PL', // 1PWR Lesotho
    'ZMB': '1PZ', // 1PWR Zambia  
    'BEN': '1PB', // 1PWR Benin
  };
  return orgMap[code] || '1PL'; // Default to Lesotho
}

/**
 * Check if AM API is available
 */
export async function checkAMConnection(): Promise<boolean> {
  try {
    const response = await fetch(`${AM_API_BASE}/vehicles/`, {
      method: 'HEAD',
    });
    return response.ok;
  } catch {
    return false;
  }
}

// Example usage in referenceData.ts:
/*
import { fetchVehiclesFromAM } from './amIntegration';

// In your loadVehicles function:
export async function loadVehicles(): Promise<ReferenceDataItem[]> {
  // Fetch from AM (single source of truth)
  const amVehicles = await fetchVehiclesFromAM();
  
  if (amVehicles.length > 0) {
    return amVehicles;
  }
  
  // Fallback to local data if AM is unavailable
  console.warn('[Vehicles] AM unavailable, using cached data');
  return loadLocalVehicles();
}
*/
