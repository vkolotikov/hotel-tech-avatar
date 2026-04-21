import { useQuery } from '@tanstack/react-query';
import { listAvatars } from '../api/avatars';

export function useAvatars() {
  return useQuery({
    queryKey: ['avatars', 'wellness'],
    queryFn: () => listAvatars('wellness'),
    staleTime: Infinity,
  });
}
