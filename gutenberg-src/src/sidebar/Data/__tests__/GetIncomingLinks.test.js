import React from 'react';
import { render } from '@testing-library/react';
import { GetIncomingLinks } from '../GetIncomingLinks';

beforeEach(() => {
    global.fetch = jest.fn();
});

afterEach(() => {
    jest.restoreAllMocks();
});

describe('GetIncomingLinks', () => {
    it('renders nothing', () => {
        global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve({}) });
        const { container } = render(<GetIncomingLinks onChange={jest.fn()} />);
        expect(container.firstChild).toBeFalsy();
    });

    it('calls the API with current post id on mount', async () => {
        const mockData = { links: [] };
        global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve(mockData) });
        const onChange = jest.fn();

        render(<GetIncomingLinks onChange={onChange} />);

        await new Promise(r => setTimeout(r, 0));

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toContain('linkate_generate_json');

        const body = JSON.parse(global.fetch.mock.calls[0][1].body);
        expect(body.this_id).toBe(42); // from setup.js mock
        expect(body.this_type).toBe('post');
    });

    it('passes API response to onChange', async () => {
        const mockData = { links: [{ source_id: 99 }] };
        global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve(mockData) });
        const onChange = jest.fn();

        render(<GetIncomingLinks onChange={onChange} />);
        await new Promise(r => setTimeout(r, 0));

        expect(onChange).toHaveBeenCalledWith(mockData);
    });

    it('logs error and does not call onChange on fetch failure', async () => {
        global.fetch = jest.fn().mockRejectedValue(new Error('Network error'));
        const onChange = jest.fn();
        const consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {});

        render(<GetIncomingLinks onChange={onChange} />);
        await new Promise(r => setTimeout(r, 0));

        expect(onChange).not.toHaveBeenCalled();
        expect(consoleSpy).toHaveBeenCalled();
    });
});
