import { initializeApp } from 'firebase/app';
import { getAuth } from 'firebase/auth';
import { getFirestore } from 'firebase/firestore';

const firebaseConfig = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY || 'AIzaSyD0tA1fvWs5dCr-7JqJv_bxlay2Bhs72jQ',
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN || 'pr-system-4ea55.firebaseapp.com',
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID || 'pr-system-4ea55',
  storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET || 'pr-system-4ea55.firebasestorage.app',
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID || '562987209098',
  appId: import.meta.env.VITE_FIREBASE_APP_ID || '1:562987209098:web:2f788d189f1c0867cb3873',
};

const app = initializeApp(firebaseConfig);

export const auth = getAuth(app);
export const db = getFirestore(app);
export default app;
