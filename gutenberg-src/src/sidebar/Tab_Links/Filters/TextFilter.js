// import React, {useEffect} from 'react';
// import { TextControl } from '@wordpress/components';
 
// // const TextFilter = withState( {
// //     className: '',
// // } )( ( { className, setState } ) => ( 
// //     <TextControl
// //         label="Additional CSS Class"
// //         value={ className }
// //         onChange={ ( className ) => setState( { className } ) }
// //     />
// // ) );

// export class TextFilter extends React.Component {

//     constructor(props) {
//         super(props);

//         this.state = {
//             text: '',
//           };
//         this.timer = null;

//         this.handleONChange = this.handleONChange.bind(this);
//         this.onTimerEnds = this.onTimerEnds.bind(this);
//     }

//     componentDidUpdate (prevProps, prevState) {
//         if(prevState.text !== this.state.text) {
//           this.handleCheck();
//         }
//       }

//     handleCheck = () => {
//         // Clears running timer and starts a new one each time the user types
//         clearTimeout(this.timer);
//         this.timer = setTimeout(() => {
//             this.onTimerEnds();
//         }, 1000);
//     }


//     handleONChange(text) {

//         this.setState((prevState) => ({
//             ...prevState,
//             text: text
//         }))
//     }

//     onTimerEnds() {
//         this.props.onChange(this.state.text);
//     }


//     render () {
//         const { restoredText } = this.props;
//         return (
//             <TextControl
//                 className="cherry-text-filter"
//                 value={ restoredText }
//                 placeholder='Поиск по сайту'
//                 onChange={ this.handleONChange }
//             />
//         )
//     }
// }



import React, {useState, useEffect, useRef} from "react";
import { TextControl } from '@wordpress/components';
export const TextFilter = (props) => {
    const filterRef = useRef(); // use ref to call the API call all time except first time
    const [serpQuery, setSerpQuery] = useState(false);

    useEffect(() => {
        let delayTimeOutFunction;

        if(!filterRef.current) {
            filterRef.current = true;

        } else { // componentDidMount equivalent
            delayTimeOutFunction = setTimeout(() => {
                // console.log('call api: ', serpQuery)
                props.onChange(serpQuery);
            }, 700); // denounce delay
        }
        return () => clearTimeout(delayTimeOutFunction);
    }, [serpQuery]);

    return (
        
        <TextControl
            className="cherry-text-filter"
            value={ serpQuery === false ? props.restoredText : serpQuery }
            placeholder='Поиск по сайту'
            onChange={ setSerpQuery }
        />
    );
};