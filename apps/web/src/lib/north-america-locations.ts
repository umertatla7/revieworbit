export type CountryCode = "US" | "CA";

export const countries: Array<{ value: CountryCode; label: string }> = [
  { value: "US", label: "USA" },
  { value: "CA", label: "Canada" },
];

export const regions: Record<CountryCode, Array<{ value: string; label: string }>> = {
  US: [
    ["AL", "Alabama"], ["AK", "Alaska"], ["AZ", "Arizona"], ["AR", "Arkansas"],
    ["CA", "California"], ["CO", "Colorado"], ["CT", "Connecticut"], ["DE", "Delaware"],
    ["DC", "District of Columbia"], ["FL", "Florida"], ["GA", "Georgia"], ["HI", "Hawaii"],
    ["ID", "Idaho"], ["IL", "Illinois"], ["IN", "Indiana"], ["IA", "Iowa"],
    ["KS", "Kansas"], ["KY", "Kentucky"], ["LA", "Louisiana"], ["ME", "Maine"],
    ["MD", "Maryland"], ["MA", "Massachusetts"], ["MI", "Michigan"], ["MN", "Minnesota"],
    ["MS", "Mississippi"], ["MO", "Missouri"], ["MT", "Montana"], ["NE", "Nebraska"],
    ["NV", "Nevada"], ["NH", "New Hampshire"], ["NJ", "New Jersey"], ["NM", "New Mexico"],
    ["NY", "New York"], ["NC", "North Carolina"], ["ND", "North Dakota"], ["OH", "Ohio"],
    ["OK", "Oklahoma"], ["OR", "Oregon"], ["PA", "Pennsylvania"], ["RI", "Rhode Island"],
    ["SC", "South Carolina"], ["SD", "South Dakota"], ["TN", "Tennessee"], ["TX", "Texas"],
    ["UT", "Utah"], ["VT", "Vermont"], ["VA", "Virginia"], ["WA", "Washington"],
    ["WV", "West Virginia"], ["WI", "Wisconsin"], ["WY", "Wyoming"],
  ].map(([value, label]) => ({ value, label })),
  CA: [
    ["AB", "Alberta"], ["BC", "British Columbia"], ["MB", "Manitoba"],
    ["NB", "New Brunswick"], ["NL", "Newfoundland and Labrador"], ["NS", "Nova Scotia"],
    ["NT", "Northwest Territories"], ["NU", "Nunavut"], ["ON", "Ontario"],
    ["PE", "Prince Edward Island"], ["QC", "Quebec"], ["SK", "Saskatchewan"], ["YT", "Yukon"],
  ].map(([value, label]) => ({ value, label })),
};

const citySuggestions: Record<string, string[]> = {
  US_AL: ["Birmingham", "Huntsville", "Montgomery"], US_AK: ["Anchorage", "Fairbanks", "Juneau"],
  US_AZ: ["Phoenix", "Scottsdale", "Tucson"], US_AR: ["Fayetteville", "Little Rock", "Springdale"],
  US_CA: ["Los Angeles", "San Diego", "San Francisco", "San Jose", "Sacramento"],
  US_CO: ["Denver", "Colorado Springs", "Aurora"], US_CT: ["Bridgeport", "Hartford", "New Haven"],
  US_DC: ["Washington"], US_DE: ["Dover", "Newark", "Wilmington"],
  US_FL: ["Jacksonville", "Miami", "Orlando", "Tampa"], US_GA: ["Atlanta", "Augusta", "Savannah"],
  US_HI: ["Honolulu", "Hilo", "Kailua"], US_ID: ["Boise", "Idaho Falls", "Meridian"],
  US_IL: ["Chicago", "Naperville", "Springfield"], US_IN: ["Fort Wayne", "Indianapolis", "South Bend"],
  US_IA: ["Cedar Rapids", "Des Moines", "Davenport"], US_KS: ["Kansas City", "Overland Park", "Wichita"],
  US_KY: ["Lexington", "Louisville", "Bowling Green"], US_LA: ["Baton Rouge", "Lafayette", "New Orleans"],
  US_MA: ["Boston", "Cambridge", "Worcester"], US_MD: ["Annapolis", "Baltimore", "Frederick"],
  US_ME: ["Bangor", "Lewiston", "Portland"], US_MI: ["Ann Arbor", "Detroit", "Grand Rapids"],
  US_MN: ["Minneapolis", "Rochester", "Saint Paul"], US_MO: ["Kansas City", "Springfield", "St. Louis"],
  US_MS: ["Gulfport", "Jackson", "Southaven"], US_MT: ["Billings", "Bozeman", "Missoula"],
  US_NC: ["Charlotte", "Greensboro", "Raleigh"], US_ND: ["Bismarck", "Fargo", "Grand Forks"],
  US_NE: ["Lincoln", "Omaha", "Bellevue"], US_NH: ["Concord", "Manchester", "Nashua"],
  US_NJ: ["Jersey City", "Newark", "Paterson"], US_NM: ["Albuquerque", "Las Cruces", "Santa Fe"],
  US_NV: ["Henderson", "Las Vegas", "Reno"], US_NY: ["Albany", "Buffalo", "New York", "Rochester"],
  US_OH: ["Cincinnati", "Cleveland", "Columbus", "Toledo"], US_OK: ["Norman", "Oklahoma City", "Tulsa"],
  US_OR: ["Bend", "Eugene", "Portland"], US_PA: ["Allentown", "Philadelphia", "Pittsburgh"],
  US_RI: ["Cranston", "Providence", "Warwick"], US_SC: ["Charleston", "Columbia", "Greenville"],
  US_SD: ["Aberdeen", "Rapid City", "Sioux Falls"], US_TN: ["Chattanooga", "Knoxville", "Memphis", "Nashville"],
  US_TX: ["Austin", "Dallas", "Fort Worth", "Houston", "San Antonio"],
  US_UT: ["Ogden", "Provo", "Salt Lake City"], US_VA: ["Alexandria", "Richmond", "Virginia Beach"],
  US_VT: ["Burlington", "Montpelier", "Rutland"], US_WA: ["Bellevue", "Seattle", "Spokane", "Tacoma"],
  US_WI: ["Green Bay", "Madison", "Milwaukee"], US_WV: ["Charleston", "Huntington", "Morgantown"],
  US_WY: ["Casper", "Cheyenne", "Laramie"],
  CA_AB: ["Calgary", "Edmonton", "Red Deer"], CA_BC: ["Burnaby", "Surrey", "Vancouver", "Victoria"],
  CA_MB: ["Brandon", "Steinbach", "Winnipeg"], CA_NB: ["Fredericton", "Moncton", "Saint John"],
  CA_NL: ["Corner Brook", "Mount Pearl", "St. John's"], CA_NS: ["Dartmouth", "Halifax", "Sydney"],
  CA_NT: ["Hay River", "Inuvik", "Yellowknife"], CA_NU: ["Arviat", "Iqaluit", "Rankin Inlet"],
  CA_ON: ["Hamilton", "London", "Mississauga", "Ottawa", "Toronto"],
  CA_PE: ["Charlottetown", "Cornwall", "Summerside"], CA_QC: ["Gatineau", "Laval", "Montreal", "Quebec City"],
  CA_SK: ["Prince Albert", "Regina", "Saskatoon"], CA_YT: ["Dawson City", "Watson Lake", "Whitehorse"],
};

export function citiesFor(country: CountryCode, region: string): string[] {
  return citySuggestions[`${country}_${region}`] ?? [];
}
