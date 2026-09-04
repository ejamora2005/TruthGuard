import { useCallback, useEffect, useState } from 'react';
import { fetchAdminJson } from '../lib/admin-api';

export function useAdminQuery(endpoint, initialData) {
    const [data, setData] = useState(initialData);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        setError('');

        try {
            const payload = await fetchAdminJson(endpoint);
            setData(payload);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Something went wrong while loading this page.');
        } finally {
            setLoading(false);
        }
    }, [endpoint]);

    useEffect(() => {
        load();
    }, [load]);

    return { data, loading, error, load };
}
