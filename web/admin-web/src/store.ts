import { create } from 'zustand';
import http from './api';

export interface CurrentUser {
  id: number;
  email: string;
  username: string;
  avatar: string;
  platform_role: string;
}

export interface MyServer {
  id: number;
  name: string;
  slug: string;
  logo: string;
  my_role: string;
}

interface AuthState {
  token: string | null;
  user: CurrentUser | null;
  servers: MyServer[];
  serversLoaded: boolean;
  setAuth: (token: string, user: CurrentUser) => void;
  logout: () => void;
  loadServers: () => Promise<void>;
}

export const useAuth = create<AuthState>((set) => ({
  token: localStorage.getItem('exre_token'),
  user: JSON.parse(localStorage.getItem('exre_user') || 'null'),
  servers: [],
  serversLoaded: false,
  setAuth: (token, user) => {
    localStorage.setItem('exre_token', token);
    localStorage.setItem('exre_user', JSON.stringify(user));
    set({ token, user, servers: [], serversLoaded: false });
  },
  logout: () => {
    localStorage.removeItem('exre_token');
    localStorage.removeItem('exre_user');
    set({ token: null, user: null, servers: [], serversLoaded: false });
  },
  loadServers: async () => {
    try {
      const data = await http.get('/server/mine');
      set({ servers: data.list || [], serversLoaded: true });
    } catch {
      set({ serversLoaded: true });
    }
  },
}));
