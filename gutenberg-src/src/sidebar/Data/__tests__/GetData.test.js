import React from 'react';
import { render } from '@testing-library/react';
import { GetData } from '../GetData';

const baseData = {
    data: [],
    dataOffset: 0,
    loadLimit: 10,
    dataStatus: 0,
    inProgress: false,
    updateRequested: false,
};

const baseFilters = { textFilter: '' };

function makeProps(overrides = {}) {
    return {
        currentData: { ...baseData },
        currentFilters: { ...baseFilters },
        onDataChange: jest.fn(),
        ...overrides,
    };
}

beforeEach(() => {
    global.fetch = jest.fn();
});

afterEach(() => {
    jest.restoreAllMocks();
});

describe('GetData', () => {
    it('renders null', () => {
        const { container } = render(<GetData {...makeProps()} />);
        expect(container.firstChild).toBeNull();
    });

    it('does not fetch on mount when updateRequested is false', () => {
        render(<GetData {...makeProps()} />);
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('fetches on mount when updateRequested is true', async () => {
        const linksResponse = { count: 0, links: '[]' };
        global.fetch = jest.fn().mockResolvedValue({
            json: () => Promise.resolve(linksResponse),
        });
        const handleDataUpdate = jest.fn();

        render(<GetData {...makeProps({
            currentData: { ...baseData, updateRequested: true },
            onDataChange: handleDataUpdate,
        })} />);

        // flush microtasks
        await Promise.resolve();
        await Promise.resolve();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toContain('get_linkate_links');
    });

    it('calls onDataChange with inProgress:true immediately, then inProgress:false after response', async () => {
        const linksResponse = { count: 0, links: '[]' };
        global.fetch = jest.fn().mockResolvedValue({
            json: () => Promise.resolve(linksResponse),
        });
        const handleDataUpdate = jest.fn();

        render(<GetData {...makeProps({
            currentData: { ...baseData, updateRequested: true },
            onDataChange: handleDataUpdate,
        })} />);

        // first call is synchronous — marks inProgress
        expect(handleDataUpdate).toHaveBeenNthCalledWith(1, expect.objectContaining({ inProgress: true, updateRequested: false }));

        await new Promise(r => setTimeout(r, 0));

        const lastCall = handleDataUpdate.mock.calls.at(-1)[0];
        expect(lastCall.inProgress).toBe(false);
    });

    it('getLinkStats skips fetch when all items already have data-incoming', async () => {
        const dataWithIncoming = [{ 'data-postid': 1, 'data-incoming': 5 }];
        const linksResponse = { count: 1, links: JSON.stringify(dataWithIncoming) };
        global.fetch = jest.fn().mockResolvedValue({
            json: () => Promise.resolve(linksResponse),
        });

        render(<GetData {...makeProps({
            currentData: { ...baseData, updateRequested: true },
            onDataChange: jest.fn(),
        })} />);

        await new Promise(r => setTimeout(r, 0));

        // only one fetch call — getLinkStats should have short-circuited
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });
});
